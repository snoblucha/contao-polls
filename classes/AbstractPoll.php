<?php

/**
 * polls extension for Contao Open Source CMS
 *
 * Copyright (C) 2013 Codefog
 *
 * @package polls
 * @author  Codefog <http://codefog.pl>
 * @author  Kamil Kuzminski <kamil.kuzminski@codefog.pl>
 * @license LGPL
 */

namespace Polls;

use Contao\Database\Result;
use Contao\Widget;


/**
 * Provide methods to handle polls.
 */
abstract class AbstractPoll extends \Frontend {

	/**
	 * Cookie name prefix
	 * @var string
	 */
	protected $strCookie = 'CONTAO_POLL_';

	/**
	 * Template for poll
	 * @var string
	 */
	protected $strPollTemplate = 'poll_default';

	/**
	 * Current poll object
	 * @var object
	 */
	protected $objPoll;


	/**
	 * Initialize the object
	 * @param integer
	 */
	public function __construct( $intPoll ) {
		parent::__construct();

		$objPoll = \Database::getInstance()->prepare( $this->getPollQuery( 'tl_poll' ) )
		                    ->limit( 1 )
		                    ->execute( $intPoll );

		if ( $objPoll->numRows ) {
			$this->objPoll = $objPoll;
		}
	}


	/**
	 * Return a poll property
	 * @param string
	 * @return mixed
	 */
	public function __get( $strKey ) {
		return isset( $this->objPoll->$strKey ) ? $this->objPoll->$strKey : null;
	}


	/**
	 * Generate a poll and return it as HTML string
	 * @return string
	 */
	public function generate() {
		if ( !$this->objPoll->numRows || !$this->objPoll->options ) {
			return '';
		}

		$blnShowResults = false;
		$objTemplate = new \FrontendTemplate( $this->strPollTemplate );
		$objTemplate->setData( $this->objPoll->row() );

		$objTemplate->cssClass = '';
		$objTemplate->message = '';
		$objTemplate->showResults = $blnShowResults;
		$objTemplate->showForm = false;

		// Display a "login to vote" message
		if ( $this->objPoll->protected && !FE_USER_LOGGED_IN ) {
			$objTemplate->cssClass .= ' protected';
			$objTemplate->mclass = 'info login';
			$objTemplate->message = $GLOBALS['TL_LANG']['MSC']['loginToVote'];
		}

		$time = time();
		$blnActive = ( $this->objPoll->closed || ( ( $this->objPoll->activeStart != '' && $this->objPoll->activeStart > $time ) || ( $this->objPoll->activeStop != '' && $this->objPoll->activeStop < $time ) ) ) ? false : true;
		$strFormId = 'poll_' . $this->objPoll->id;
		$objTemplate->title = $this->objPoll->title;
		$objTemplate->active = $blnActive;
		$objTemplate->cssClass .= $this->objPoll->featured ? ' featured' : '';

		// Display a message if the poll is inactive
		if ( !$blnActive ) {
			$objTemplate->cssClass .= ' closed';
			$objTemplate->mclass = 'info closed';
			$objTemplate->message = $GLOBALS['TL_LANG']['MSC']['pollClosed'];
		}

		$blnHasVoted = $this->hasVoted();

		// Display a confirmation message
		if ( $_SESSION['POLL'][$this->objPoll->id] != '' ) {
			$blnJustVoted = true;
			$objTemplate->mclass = 'confirm';
			$objTemplate->message = $_SESSION['POLL'][$this->objPoll->id];
			unset( $_SESSION['POLL'][$this->objPoll->id] );
		}

		$objTemplate->hasVoted = $blnHasVoted;

		// Check if we should display the results
		if ( ( $blnActive && !$blnHasVoted && ( ( $this->objPoll->active_behaviorNotVoted == 'opt1' && \Input::get( 'results' ) == $this->objPoll->id ) || ( $this->objPoll->active_behaviorNotVoted == 'opt3' && ( !\Input::get( 'vote' ) || \Input::get( 'vote' ) != $this->objPoll->id ) ) ) ) || ( $blnActive && $blnHasVoted && ( ( $this->objPoll->active_behaviorVoted == 'opt2' && \Input::get( 'results' ) == $this->objPoll->id ) || ( $this->objPoll->active_behaviorVoted == 'opt1' && ( $blnJustVoted || !\Input::get( 'vote' ) || \Input::get( 'vote' ) != $this->objPoll->id ) ) ) ) || ( !$blnActive && !$blnHasVoted && ( ( $this->objPoll->inactive_behaviorNotVoted == 'opt1' && \Input::get( 'results' ) == $this->objPoll->id ) || ( $this->objPoll->inactive_behaviorNotVoted == 'opt3' && ( !\Input::get( 'vote' ) || \Input::get( 'vote' ) != $this->objPoll->id ) ) ) ) || ( !$blnActive && $blnHasVoted && ( ( $this->objPoll->inactive_behaviorVoted == 'opt2' && \Input::get( 'results' ) == $this->objPoll->id ) || ( $this->objPoll->inactive_behaviorVoted == 'opt1' && ( !\Input::get( 'vote' ) || \Input::get( 'vote' ) != $this->objPoll->id ) ) ) ) ) {
			$blnShowResults = true;
		}

		$objOptions = \Database::getInstance()->prepare( $this->getPollQuery( 'tl_poll_option' ) )
		                       ->execute( $this->objPoll->id );

		// Display results under certain circumstances
		if ( $blnShowResults ) {

			$intVotes = array_sum( $objOptions->fetchEach( 'votes' ) );
			$arrResults = $this->generateResults( $intVotes, $objOptions );

			$objTemplate->showResults = $blnShowResults;
			$objTemplate->total = $intVotes;
			$objTemplate->results = $arrResults;
			$objTemplate->formLink = '';

			// Display the form link
			if ( $blnActive && !$blnHasVoted ) {
				$objTemplate->formLink = sprintf( '<a href="%s" class="vote_link" title="%s">%s</a>', $this->generatePollUrl( 'vote' ), specialchars( $GLOBALS['TL_LANG']['MSC']['showForm'] ), $GLOBALS['TL_LANG']['MSC']['showForm'] );
			}

			return $objTemplate->parse();
		}

		$doNotSubmit = false;

		$arrOptions = $this->generateOptions( $objOptions );
		$objWidget = $this->generateWidget( $arrOptions );

		// Override the ID parameter to avoid ID duplicates for radio buttons and labels
		$objWidget->id = 'poll_' . $this->objPoll->id;

		// Validate the widget
		if ( \Input::post( 'FORM_SUBMIT' ) == $strFormId && !\Input::post( 'results' ) ) {
			$objWidget->validate();

			if ( $objWidget->hasErrors() ) {
				$doNotSubmit = true;
			}
		}

		$objTemplate->showForm = true;
		$objTemplate->options = $objWidget;
		$objTemplate->submit = ( !$blnActive || $blnHasVoted || ( $this->objPoll->protected && !FE_USER_LOGGED_IN ) ) ? '' : $GLOBALS['TL_LANG']['MSC']['vote'];
		$objTemplate->action = ampersand( \Environment::get( 'request' ) );
		$objTemplate->formId = $strFormId;
		$objTemplate->hasError = $doNotSubmit;
		$objTemplate->resultsLink = '';

		// Display the results link
		if ( ( $blnActive && !$blnHasVoted && $this->objPoll->active_behaviorNotVoted == 'opt1' ) || ( $blnActive && $blnHasVoted && $this->objPoll->active_behaviorVoted == 'opt2' ) || ( !$blnActive && !$blnHasVoted && $this->objPoll->inactive_behaviorNotVoted == 'opt1' ) || ( !$blnActive && $blnHasVoted && $this->objPoll->inactive_behaviorVoted == 'opt2' ) ) {
			$objTemplate->resultsLink = sprintf( '<a href="%s" class="results_link" title="%s">%s</a>', $this->generatePollUrl( 'results' ), specialchars( $GLOBALS['TL_LANG']['MSC']['showResults'] ), $GLOBALS['TL_LANG']['MSC']['showResults'] );
		}

		// Add the vote
		if ( \Input::post( 'FORM_SUBMIT' ) == $strFormId && !$doNotSubmit ) {
			if ( !$blnActive || $blnHasVoted || ( $this->objPoll->protected && !FE_USER_LOGGED_IN ) ) {
				$this->reload();
			}

			$arrValues = is_array( $objWidget->value ) ? $objWidget->value : array( $objWidget->value );

			// Set the cookie
			$this->setCookie( $this->strCookie . $this->objPoll->id, $time, ( $time + ( 365 * 86400 ) ) );

			// Store the votes
			foreach ( $arrValues as $value ) {
				$arrSet = array
				(
					'pid' => $value,
					'tstamp' => $time,
					'ip' => \Environment::get( 'ip' ),
					'member' => FE_USER_LOGGED_IN ? \FrontendUser::getInstance()->id : 0
				);

				\Database::getInstance()->prepare( "INSERT INTO tl_poll_votes %s" )->set( $arrSet )->execute();
			}

			// Redirect or reload the page
			$_SESSION['POLL'][$this->objPoll->id] = $GLOBALS['TL_LANG']['MSC']['voteSubmitted'];
			$this->jumpToOrReload( $this->jumpTo );
		}

		return $objTemplate->parse();
	}


	/**
	 * Determine whether the current user has already voted
	 * @param integer
	 * @return boolean
	 */
	public function hasVoted() {
		$intExpires = $this->objPoll->voteInterval ? ( time() - $this->objPoll->voteInterval ) : 0;

		// Check the cookie
		if ( \Input::cookie( $this->strCookie . $this->objPoll->id ) > $intExpires ) {
			return true;
		}

		if ( $this->objPoll->protected && FE_USER_LOGGED_IN ) {
			$objVote = \Database::getInstance()->prepare( "SELECT * FROM tl_poll_votes WHERE member=? AND tstamp>? AND pid IN (SELECT id FROM tl_poll_option WHERE pid=?" . ( !BE_USER_LOGGED_IN ? " AND published=1" : "" ) . ") ORDER BY tstamp DESC" )
			                    ->limit( 1 )
			                    ->execute( \FrontendUser::getInstance()->id, $intExpires, $this->objPoll->id );
		} else {
			$objVote = \Database::getInstance()->prepare( "SELECT * FROM tl_poll_votes WHERE ip=? AND tstamp>? AND pid IN (SELECT id FROM tl_poll_option WHERE pid=?" . ( !BE_USER_LOGGED_IN ? " AND published=1" : "" ) . ") ORDER BY tstamp DESC" )
			                    ->limit( 1 )
			                    ->execute( \Environment::get( 'ip' ), $intExpires, $this->objPoll->id );
		}

		// User has already voted
		if ( $objVote->numRows ) {
			return true;
		}

		return false;
	}


	/**
	 * Generate the poll URL and return it as string
	 * @param string
	 * @return string
	 */
	protected function generatePollUrl( $strKey ) {
		list( $strPage, $strQuery ) = explode( '?', \Environment::get( 'request' ), 2 );
		$arrQuery = array();

		// Parse the current query
		if ( $strQuery != '' ) {
			$arrQuery = explode( '&', $strQuery );

			// Remove the "vote" and "results" parameters
			foreach ( $arrQuery as $k => $v ) {
				list( $key, $value ) = explode( '=', $v, 2 );

				if ( $key == 'vote' || $key == 'results' ) {
					unset( $arrQuery[$k] );
				}
			}
		}

		// Add the key
		$arrQuery[] = $strKey . '=' . $this->objPoll->id;

		return ampersand( $strPage . '?' . implode( '&', $arrQuery ) );
	}


	/**
	 * Generate a select statement that includes translated fields
	 * @param string
	 * @param string
	 * @return string
	 */
	protected function getPollQuery( $strTable ) {
		$blnMultilingual = self::checkMultilingual();

		// Multilingual settings
		if ( $blnMultilingual ) {
			$arrFields = array();
			$this->loadDataContainer( $strTable );

			// Get translatable fields
			foreach ( $GLOBALS['TL_DCA'][$strTable]['fields'] as $field => $arrData ) {
				if ( $arrData['eval']['translatableFor'] != '' ) {
					$arrFields[] = "IFNULL(t2." . $field . ", t1." . $field . ") AS " . $field;
				}
			}
		}

		// Build the query
		switch ( $strTable ) {
			case 'tl_poll':
				$strQuery = "SELECT *, (SELECT COUNT(*) FROM tl_poll_option WHERE pid=tl_poll.id) AS options FROM tl_poll WHERE id=?" . ( !BE_USER_LOGGED_IN ? " AND published=1" : "" );

				if ( $blnMultilingual ) {
					$strQuery = "SELECT t1.*, " . implode( ', ', $arrFields ) . ", (SELECT COUNT(*) FROM tl_poll_option WHERE pid=t1.id) AS options FROM tl_poll t1 LEFT OUTER JOIN tl_poll t2 ON t1.id=t2.lid AND t2.language='" . $GLOBALS['TL_LANGUAGE'] . "' WHERE t1.lid=0 AND t1.id=?" . ( !BE_USER_LOGGED_IN ? " AND t1.published=1" : "" );
				}
				break;

			case 'tl_poll_option':
				$strQuery = "SELECT *, (SELECT COUNT(*) FROM tl_poll_votes WHERE pid=tl_poll_option.id) AS votes FROM tl_poll_option WHERE pid=?" . ( !BE_USER_LOGGED_IN ? " AND published=1" : "" ) . " ORDER BY sorting";

				if ( $blnMultilingual ) {
					$strQuery = "SELECT t1.*, " . implode( ', ', $arrFields ) . ", (SELECT COUNT(*) FROM tl_poll_votes WHERE pid=t1.id) AS votes FROM tl_poll_option t1 LEFT OUTER JOIN tl_poll_option t2 ON t1.id=t2.lid AND t2.language='" . $GLOBALS['TL_LANGUAGE'] . "' WHERE t1.lid=0 AND t1.pid=?" . ( !BE_USER_LOGGED_IN ? " AND t1.published=1" : "" ) . " ORDER BY t1.sorting";
				}
				break;
		}

		return $strQuery;
	}


	/**
	 * Check if there is DC_Multilingual installed
	 * @return boolean
	 */
	public static function checkMultilingual() {
		return ( file_exists( TL_ROOT . '/system/drivers/DC_Multilingual.php' ) && count( self::getAvailableLanguages() ) > 1 ) ? true : false;
	}


	/**
	 * Return a list of available languages
	 * @return array
	 */
	public static function getAvailableLanguages() {
		$objDatabase = Database::getInstance();
		return $objDatabase->execute( "SELECT DISTINCT language FROM tl_page WHERE type='root'" )->fetchEach( 'language' );
	}


	/**
	 * Get a fallback language
	 * @return string
	 */
	public static function getFallbackLanguage() {
		$objDatabase = Database::getInstance();
		return $objDatabase->execute( "SELECT language FROM tl_page WHERE type='root' AND fallback=1" )->language;
	}

	/**
	 * @param integer $intVotes
	 * @param Result $objOptions
	 * @return array
	 */
	abstract protected function generateResults( $intVotes, $objOptions );

	/**
	 * @param Result $objOptions
	 * @return array
	 */
	abstract protected function generateOptions( $objOptions );

	/**
	 * @param array $arrOptions
	 * @return Widget
	 */
	protected function generateWidget( $arrOptions ) {
		$blnClosed = $this->objPoll->closed;
		// Options form field
		$arrField = array
		(
			'name' => 'options',
			'options' => $arrOptions,
			'inputType' => ( $this->objPoll->type == 'single' ) ? 'radio' : 'checkbox',
			'eval' => array( 'mandatory' => true, 'disabled' => $blnClosed )
		);

		return new $GLOBALS['TL_FFL'][$arrField['inputType']]( $this->prepareForWidget( $arrField, $arrField['name'] ) );


	}
}
