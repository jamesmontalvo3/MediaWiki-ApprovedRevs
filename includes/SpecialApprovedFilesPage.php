<?php

/**
 * Special page that displays various lists of files that either do or do
 * not have an approved revision.
 *
 * @author James Montalvo
 */
class SpecialApprovedFilesPage extends QueryPage {

	protected $mMode;

	public function __construct( $mode ) {
		if ( $this instanceof SpecialPage ) {
			parent::__construct( 'ApprovedFiles' );
		}
		$this->mMode = $mode;
	}

	public function getName() {
		return 'ApprovedFiles';
	}

	public function isExpensive() { return false; }

	public function isSyndicated() { return false; }

	public function getPageHeader() {
		// show the names of the three lists of pages, with the one
		// corresponding to the current "mode" not being linked
		$approvedPagesTitle = SpecialPage::getTitleFor( 'ApprovedFiles' );
		$navLine = wfMessage( 'approvedrevs-view' )->parse() . ' ';

		if ( $this->mMode == '' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-notlatestfiles' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL() ),
				wfMessage( 'approvedrevs-notlatestfiles' )->text()
			);
		}

		$navLine .= ' | ';

		if ( $this->mMode == 'all' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-approvedfiles' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( array( 'show' => 'all' ) ) ),
				wfMessage( 'approvedrevs-approvedfiles' )->text()
			);
		}

		$navLine .= ' | ';

		if ( $this->mMode == 'unapproved' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-unapprovedfiles' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( array( 'show' => 'unapproved' ) ) ),
				wfMessage( 'approvedrevs-unapprovedfiles' )->text()
			);
		}

		$navLine .= ' | ';

		if ( $this->mMode == 'invalid' ) {
			$navLine .= Xml::element( 'strong',
				null,
				wfMessage( 'approvedrevs-invalidfiles' )->text()
			);
		} else {
			$navLine .= Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( array( 'show' => 'invalid' ) ) ),
				wfMessage( 'approvedrevs-invalidfiles' )->text()
			);
		}

		$navLine .= "\n";

		return Xml::tags( 'p', null, $navLine ) . "\n";
	}

	/**
	 * Set parameters for standard navigation links.
	 */
	public function linkParameters() {
		$params = array();

		if ( $this->mMode == 'all' ) {
			$params['show'] = 'all';
		} elseif ( $this->mMode == 'unapproved' ) {
			$params['show'] = 'unapproved';
		} elseif ( $this->mMode == 'invalid' ) {
			$params['show'] = 'invalid';
		} else { // 'approved revision not the latest' pages
		}

		return $params;
	}

	public function getPageFooter() {
	}

	public static function getNsConditionPart( $ns ) {
		return 'p.page_namespace = ' . $ns;
	}

	/**
	 * (non-PHPdoc)
	 * @see QueryPage::getSQL()
	 */
	public function getQueryInfo() {

		$tables = array(
			'ar' => 'approved_revs_files',
			'im' => 'image',
			'p' => 'page',
		);

		$fields = array(
			'im.img_name AS title',
			'ar.approved_sha1 AS approved_sha1',
			'ar.approved_timestamp AS approved_ts',
			'im.img_sha1 AS latest_sha1',
			'im.img_timestamp AS latest_ts',
		);

		$conds = array();

		$join_conds = array(
			'im' => array( 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' ),
			'p'  => array( 'LEFT OUTER JOIN', 'im.img_name=p.page_title' ),
		);

		#
		#	ALLFILES: list all approved pages
		#   also includes $this->mMode == 'invalid', see formatResult()
		#
		if ( $this->mMode == 'allapproved' ) {

			// get everything from approved_revs table
			$conds['p.page_namespace'] = NS_FILE;

		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapproved' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['c'] = [ 'LEFT OUTER JOIN', 'p.page_id=c.cl_from' ];
			$join_conds['im'] = [ 'RIGHT OUTER JOIN', 'ar.file_title=im.img_name' ];

			// FIXME: function doesn't exist
			$perms = ApprovedRevs::getPermissions();

			// if all files are not approvable then need to find files matching
			// page and category permissions
			if ( ! in_array( NS_FILE, array_keys( $perms['Namespace Permissions'] ) ) ) {

				// FIXME: getApprovabilityStringsForDB doesn't exist
				list( $ns, $cat, $pg ) = ApprovedRevs::getApprovabilityStringsForDB();

				$pageCatConditions = array();
				if ( $cat !== '' ) {
					$pageCatConditions[] = "c.cl_to IN ($cat)";
				}
				if ( $pg  !== '' ) {
					$pageCatConditions[] = "p.page_id IN ($pg)";
				}

				// if there were any page or category conditions, add to $conds
				if ( count( $pageCatConditions ) > 0 ) {
					$conds[] = '(' . implode( ' OR ', $pageCatConditions ) . ')';
				}

			}

			$conds['ar.file_title'] = null;
			$conds['p.page_namespace'] = NS_FILE;

		#
		#	INVALID PERMISSIONS
		#
		} elseif ( $this->mMode == 'invalid' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['c'] = [ 'LEFT OUTER JOIN', 'p.page_id=c.cl_from' ];
			$join_conds['im'] = [ 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' ];

			// FIXME: function doesn't exist
			$perms = ApprovedRevs::getPermissions();
			if ( in_array( NS_FILE, array_keys( $perms['Namespace Permissions'] ) ) ) {

				// if all files approvable should break out of
				// this...since none can be invalid if they're all
				// approvable using this impossible condition, hack
				$conds[] = 'p.page_namespace=1 AND p.page_namespace=2';
			}
			else {

				// FIXME: getApprovabilityStringsForDB doesn't exist
				list( $ns, $cat, $pg ) = ApprovedRevs::getApprovabilityStringsForDB();

				if ( $cat !== '' ) {
					$conds[] = "p.page_id NOT IN " .
						"(SELECT DISTINCT cl_from FROM ".
						"categorylinks WHERE cl_to IN ($cat))";
				}
				if ( $pg  !== '' ) {
					$conds[] = "p.page_id NOT IN ($pg)";
				}

			}

			$conds['p.page_namespace'] = NS_FILE;

		#
		#	NOTLATEST
		#
		} else {

			// Name/Title both exist, sha1's don't match OR timestamps
			// don't match
			$conds['p.page_namespace'] = NS_FILE;
			$conds[] = "(ar.approved_sha1!=im.img_sha1 OR ar.approved_timestamp!=im.img_timestamp)";

		}

		return array(
			'tables' => $tables,
			'fields' => $fields,
			'join_conds' => $join_conds,
			'conds' => $conds,
			'options' => array( 'DISTINCT' ),
		);

	}

	public function getOrder() {
		return ' ORDER BY p.page_namespace, p.page_title ASC';
	}

	public function getOrderFields() {
		return array( 'p.page_namespace', 'p.page_title' );
	}

	public function sortDescending() {
		return false;
	}

	public function formatResult( $skin, $result ) {

		$title = Title::makeTitle( NS_FILE, $result->title );

		if ( ! self::$repo ) {
			self::$repo = RepoGroup::singleton();
		}

		$pageLink = Linker::link( $title );


		#
		#	Unapproved Files
		#
		if ( $this->mMode == 'unapproved' ) {
			global $egApprovedRevsShowApproveLatest;

			if ( $egApprovedRevsShowApproveLatest && ApprovedRevs::userCanApprove( $title ) ) {
				$approveLink = ' (' . Xml::element(
					'a',
					array(
						'href' => $title->getLocalUrl(
							[
								'action' => 'approvefile',
								'ts' => $result->latest_ts,
								'sha1' => $result->latest_sha1
							]
						)
					),
					wfMessage( 'approvedrevs-approve' )->text()
				) . ')';
			}
			else {
				$approveLink = '';
			}

			return "$pageLink$approveLink";

		#
		#   Invalid Files
		#
		} elseif ( $this->mMode == 'invalid' ) {

			if ( ! ApprovedRevs::fileIsApprovable( $title ) ) {
				// if showing invalid files only, don't show files
				// that have real approvability
				return '';
			}

			return $pageLink;

		#
		#	All Files with an approved revision
		#
			// main mode (pages with an approved revision)
		} elseif ( $this->mMode == 'allapproved' ) {
			global $wgUser, $wgOut, $wgLang;

			$additionalInfo = Xml::element( 'span',
				[
					'class' =>
						( $result->approved_sha1 == $result->latest_sha1
							&& $result->approved_ts == $result->latest_ts
						) ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				],
				wfMessage(
					'approvedrevs-revisionnumber',
					substr( $result->approved_sha1, 0, 8 )
				)->parse()
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$loglist = new LogEventsList( $skin, $wgOut );
			$pager = new LogPager( $loglist, 'approval', '', $title );
			$pager->mLimit = 1;
			$pager->doQuery();

			$result = $pager->getResult();
			$row = $result->fetchObject();


			if ( ! empty( $row ) ) {
				$timestamp = $wgLang->timeanddate(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$date = $wgLang->date(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$time = $wgLang->time(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$userLink = $skin->userLink( $row->log_user, $row->user_name );
				$additionalInfo .= ', ' . wfMessage(
					'approvedrevs-approvedby',
					$userLink,
					$timestamp,
					$row->user_name,
					$date,
					$time
				)->text();
			}

			return "$pageLink ($additionalInfo)";

		#
		# Not Latest Files:
		# [[My File.jpg]] (revision 2ba82e7f approved; revision 6ac914dc latest)
		} else {

			$approved_file = self::$repo->findFileFromKey(
				$result->approved_sha1,
				array( 'time' => $result->approved_ts )
			);
			$latest_file = self::$repo->findFileFromKey(
				$result->latest_sha1,
				array( 'time' => $result->latest_ts )
			);

			$approvedLink = Xml::element( 'a',
				array( 'href' => $approved_file->getUrl() ),
				wfMessage( 'approvedrevs-approvedfile' )->text()
			);
			$latestLink = Xml::element( 'a',
				array( 'href' => $latest_file->getUrl() ),
				wfMessage( 'approvedrevs-latestfile' )->text()
			);

			return "$pageLink ($approvedLink | $latestLink)";
		}

	}

}
