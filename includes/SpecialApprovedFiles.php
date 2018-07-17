<?php

/**
 * Special page that displays various lists of files that either do or do
 * not have an approved revision.
 *
 * @author James Montalvo
 */
class SpecialApprovedFiles extends SpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'ApprovedFiles' );
	}

	function execute( $query ) {
		$request = $this->getRequest();

		ApprovedRevs::addCSS();
		$this->setHeaders();
		list( $limit, $offset ) = $request->getLimitOffset();

		$mode = $request->getVal( 'show' );
		$rep = new SpecialApprovedFilesPage( $mode );

		if ( method_exists( $rep, 'execute' ) ) {
			return $rep->execute( $query );
		} else {
			return $rep->doQuery( $offset, $limit );
		}
	}

	protected function getGroupName() {
		return 'pages';
	}
}

