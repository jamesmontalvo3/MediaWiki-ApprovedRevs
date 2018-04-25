<?php

/**
 * Parser functions for Approved Revs.
 *
 * @file
 * @ingroup AR
 *
 * The following parser functions are defined: #approvedrevs_approvers
 *
 * '#approvedrevs_approvers' is called as:
 * {{#approvedrevs_approvers:SomeUsername |SomeOtherUsername  | YetAnotherUser}}
 *
 * This function sets the usernames specified as able to approve the page.
 * Usernames are specified without the "User:" prefix.
 *
 * @author James Montalvo
 * @since 0.9
 */

class ARParserFunctions {

	/**
	 * Render #approvedrevs_approvers parser function
	 *
	 * @param Parser &$parser
	 * @return bool
	 */
	public static function renderApprovedRevsApprovers( &$parser ) {
		$curTitle = $parser->getTitle();
		$params = func_get_args();
		array_shift( $params );
		if ( count( $params ) == 0 ) {
			// No users specified, nothing to do.
			return true;
		}
		$approvers = array_map( 'trim', $params );
		$parserOutput = $parser->getOutput();
		// store value as string imploded with |, since | is NOT a valid title
		// (and thus username) character
		$parserOutput->setProperty( 'approvedrevs-approvers', implode( '|', $approvers ) );
		// no output. allow users to stylize output themselves.
		return '';
	}

}
