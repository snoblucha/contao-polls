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


/**
 * Provide methods to handle polls.
 */
class Poll extends AbstractPoll {

	/**
	 * @param $intVotes
	 * @param Result $objOptions
	 * @return array
	 */
	protected function generateResults( $intVotes, $objOptions ) {
		$arrResults = array();
		// Generate results
		$objOptions->reset();
		while ( $objOptions->next() ) {

			$arrResults[] = array
			(
				'title' => $objOptions->title,
				'votes' => $objOptions->votes,
				'prcnt' => ( $intVotes > 0 ) ? ( round( ( $objOptions->votes / $intVotes ), 2 ) * 100 ) : 0
			);
		}
		return $arrResults;
	}

	protected function generateOptions( $objOptions ) {
		$arrOptions = array();
		while ( $objOptions->next() ) {
			$arrOptions[$objOptions->id] = $objOptions->title;
		}
		return $arrOptions;
	}


}
