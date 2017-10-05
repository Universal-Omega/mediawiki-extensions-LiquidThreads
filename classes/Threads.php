<?php

/** Module of factory methods. */
class Threads {
	const TYPE_NORMAL = 0;
	const TYPE_MOVED = 1;
	const TYPE_DELETED = 2;
	const TYPE_HIDDEN = 4;

	const CHANGE_NEW_THREAD = 0;
	const CHANGE_REPLY_CREATED = 1;
	const CHANGE_EDITED_ROOT = 2;
	const CHANGE_EDITED_SUMMARY = 3;
	const CHANGE_DELETED = 4;
	const CHANGE_UNDELETED = 5;
	const CHANGE_MOVED_TALKPAGE = 6;
	const CHANGE_SPLIT = 7;
	const CHANGE_EDITED_SUBJECT = 8;
	const CHANGE_PARENT_DELETED = 9;
	const CHANGE_MERGED_FROM = 10;
	const CHANGE_MERGED_TO = 11;
	const CHANGE_SPLIT_FROM = 12;
	const CHANGE_ROOT_BLANKED = 13;
	const CHANGE_ADJUSTED_SORTKEY = 14;
	const CHANGE_EDITED_SIGNATURE = 15;

	public static $VALID_CHANGE_TYPES = [
		self::CHANGE_EDITED_SUMMARY,
		self::CHANGE_EDITED_ROOT,
		self::CHANGE_REPLY_CREATED,
		self::CHANGE_NEW_THREAD,
		self::CHANGE_DELETED,
		self::CHANGE_UNDELETED,
		self::CHANGE_MOVED_TALKPAGE,
		self::CHANGE_SPLIT,
		self::CHANGE_EDITED_SUBJECT,
		self::CHANGE_PARENT_DELETED,
		self::CHANGE_MERGED_FROM,
		self::CHANGE_MERGED_TO,
		self::CHANGE_SPLIT_FROM,
		self::CHANGE_ROOT_BLANKED,
		self::CHANGE_ADJUSTED_SORTKEY,
		self::CHANGE_EDITED_SIGNATURE,
	];

	// Possible values of Thread->editedness.
	const EDITED_NEVER = 0;
	const EDITED_HAS_REPLY = 1;
	const EDITED_BY_AUTHOR = 2;
	const EDITED_BY_OTHERS = 3;

	public static $cache_by_root = [];
	public static $cache_by_id = [];
	public static $occupied_titles = [];

	/**
	 * Create the talkpage if it doesn't exist so that links to it
	 * will show up blue instead of red. For use upon new thread creation.
	 *
	 * @param Article $talkpage
	 */
	public static function createTalkpageIfNeeded( $talkpage ) {
		if ( !$talkpage->exists() ) {
			try {

				$talkpage->getPage()->doEditContent(
					ContentHandler::makeContent( "", $talkpage->getTitle() ),
					wfMessage( 'lqt_talkpage_autocreate_summary' )->inContentLanguage()->text(),
					EDIT_NEW | EDIT_SUPPRESS_RC
				);
			} catch ( DBQueryError $e ) {
				// The article already existed by now. No need to do anything.
				wfDebug( __METHOD__ . ": Article already exists." );
			}
		}
	}

	public static function loadFromResult( $res, $db, $bulkLoad = false ) {
		$rows = [];
		$threads = [];

		foreach ( $res as $row ) {
			$rows[] = $row;

			if ( !$bulkLoad ) {
				$threads[$row->thread_id] = Thread::newFromRow( $row );
			}
		}

		if ( !$bulkLoad ) {
			return $threads;
		}

		return Thread::bulkLoad( $rows );
	}

	public static function where( $where, $options = [], $bulkLoad = true ) {
		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select( 'thread', '*', $where, __METHOD__, $options );
		$threads = self::loadFromResult( $res, $dbr, $bulkLoad );

		foreach ( $threads as $thread ) {
			if ( $thread->root() ) {
				self::$cache_by_root[$thread->root()->getId()] = $thread;
			}
			self::$cache_by_id[$thread->id()] = $thread;
		}

		return $threads;
	}

	private static function databaseError( $msg ) {
		// @todo Tie into MW's error reporting facilities.
		throw new Exception( "Corrupt LiquidThreads database: $msg" );
	}

	private static function assertSingularity( $threads, $attribute, $value ) {
		if ( count( $threads ) == 0 ) {
			return null;
		}

		if ( count( $threads ) == 1 ) {
			return array_pop( $threads );
		}

		if ( count( $threads ) > 1 ) {
			self::databaseError( "More than one thread with $attribute = $value." );
		}

		return null;
	}

	/**
	 * @param Article $post
	 * @param bool $bulkLoad
	 * @return Thread
	 */
	public static function withRoot( $post, $bulkLoad = true ) {
		if ( $post->getTitle()->getNamespace() != NS_LQT_THREAD ) {
			// No articles outside the thread namespace have threads associated with them;
			return null;
		}

		if ( $post->getId() == 0 ) {
			// Page ID zero doesn't exist.
			return null;
		}

		if ( array_key_exists( $post->getId(), self::$cache_by_root ) ) {
			return self::$cache_by_root[$post->getId()];
		}

		$ts = self::where( [ 'thread_root' => $post->getId() ], [], $bulkLoad );

		return self::assertSingularity( $ts, 'thread_root', $post->getId() );
	}

	/**
	 * @param string $id
	 * @param bool $bulkLoad
	 * @return Thread
	 */
	public static function withId( $id, $bulkLoad = true ) {
		if ( array_key_exists( $id, self::$cache_by_id ) ) {
			return self::$cache_by_id[$id];
		}

		$ts = self::where( [ 'thread_id' => $id ], [], $bulkLoad );

		return self::assertSingularity( $ts, 'thread_id', $id );
	}

	/**
	 * @param Article $article
	 * @param bool $bulkLoad
	 * @return Thread
	 */
	public static function withSummary( $article, $bulkLoad = true ) {
		$ts = self::where( [ 'thread_summary_page' => $article->getId() ],
			[], $bulkLoad );
		return self::assertSingularity( $ts, 'thread_summary_page', $article->getId() );
	}

	public static function articleClause( $article ) {
		$dbr = wfGetDB( DB_REPLICA );

		$titleCond = [ 'thread_article_title' => $article->getTitle()->getDBKey(),
			'thread_article_namespace' => $article->getTitle()->getNamespace() ];
		$titleCond = $dbr->makeList( $titleCond, LIST_AND );

		$conds = [ $titleCond ];

		if ( $article->getId() ) {
			$idCond = [ 'thread_article_id' => $article->getId() ];
			$conds[] = $dbr->makeList( $idCond, LIST_AND );
		}

		return $dbr->makeList( $conds, LIST_OR );
	}

	public static function topLevelClause() {
		$dbr = wfGetDB( DB_REPLICA );

		$arr = [ 'thread_ancestor=thread_id', 'thread_parent' => null ];

		return $dbr->makeList( $arr, LIST_OR );
	}

	public static function newThreadTitle( $subject, $article ) {
		$base = $article->getTitle()->getPrefixedText() . "/$subject";

		return self::incrementedTitle( $base, NS_LQT_THREAD );
	}

	public static function newSummaryTitle( $t ) {
		return self::incrementedTitle( $t->title()->getText(), NS_LQT_SUMMARY );
	}

	public static function newReplyTitle( $thread, $user ) {
		$topThread = $thread->topmostThread();

		$base = $topThread->title()->getText() . '/'
			. wfMessage( 'lqt-reply-subpage' )->inContentLanguage()->text();

		return self::incrementedTitle( $base, NS_LQT_THREAD );
	}

	// This will attempt to replace invalid characters and sequences in a title with
	// a safe replacement (_, currently). Before doing this, it will parse any wikitext
	// and strip the HTML, before converting HTML entities back into their corresponding
	// characters.
	public static function makeTitleValid( $text ) {
		$text = self::stripWikitext( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		static $rxTc;

		if ( is_callable( 'MediaWikiTitleCodec::getTitleInvalidRegex' ) ) {
			$rxTc = MediaWikiTitleCodec::getTitleInvalidRegex();
		} elseif ( is_callable( [ 'Title', 'getTitleInvalidRegex' ] ) ) { // Pre-1.25 compat
			$rxTc = Title::getTitleInvalidRegex();
		} elseif ( !$rxTc ) { // Back-compat
			$rxTc = '/' .
				# Any character not allowed is forbidden...
				'[^' . Title::legalChars() . ']' .
				# URL percent encoding sequences interfere with the ability
				# to round-trip titles -- you can't link to them consistently.
				'|%[0-9A-Fa-f]{2}' .
				# XML/HTML character references produce similar issues.
				'|&[A-Za-z0-9\x80-\xff]+;' .
				'|&#[0-9]+;' .
				'|&#x[0-9A-Fa-f]+;' .
				'/S';
		}

		$text = preg_replace( $rxTc, '_', $text );

		return $text;
	}

	// This will strip wikitext of its formatting.
	public static function stripWikitext( $text ) {
		global $wgOut;
		$text = $wgOut->parseInline( $text );

		$text = StringUtils::delimiterReplace( '<', '>', '', $text );

		return $text;
	}

	public static function stripHTML( $text ) {
		return StringUtils::delimiterReplace( '<', '>', '', $text );
	}

	/**
	 * Keep trying titles starting with $basename until one is unoccupied.
	 * @param string $basename
	 * @param int $namespace
	 * @return Title
	 */
	public static function incrementedTitle( $basename, $namespace ) {
		global $wgContLang;
		$i = 2;

		// Try to make the title valid.
		$basename = self::makeTitleValid( $basename );

		$t = Title::makeTitleSafe( $namespace, $basename );
		while ( !$t ||
			in_array( $t->getPrefixedDBkey(), self::$occupied_titles ) ||
			$t->exists() ||
			$t->isDeletedQuick()
		) {
			if ( !$t ) {
				throw new Exception( "Error in creating title for basename $basename" );
			}

			$n = $wgContLang->formatNum( $i );
			$t = Title::makeTitleSafe( $namespace, $basename . ' (' . $n . ')' );
			$i++;
		}
		return $t;
	}

	// Called just before any function that might cause a loss of article association.
	// by breaking either a NS-title reference (by moving the article), or a page-id
	// reference (by deleting the article).
	// Basically ensures that all subthreads have the two stores of article association
	// synchronised.
	// Can also be called with a "limit" parameter to slowly convert old threads. This
	// is intended to be used by jobs created by move and create operations to slowly
	// propagate the change through the data set without rushing the whole conversion
	// when a second breaking change is made. If a limit is set and more rows require
	// conversion, this function will return false. Otherwise, true will be returned.
	// If the queueMore parameter is set and rows are left to update, a job queue item
	// will then be added with the same limit, to finish the remainder of the update.
	public static function synchroniseArticleData( $article, $limit = false, $queueMore = false ) {
		if ( !$article ) {
			throw new Exception( "synchroniseArticleData called on null article" );
		}

		$dbr = wfGetDB( DB_REPLICA );
		$dbw = wfGetDB( DB_MASTER );

		$title = $article->getTitle();
		$id = $article->getId();

		$titleCond = [ 'thread_article_namespace' => $title->getNamespace(),
			'thread_article_title' => $title->getDBkey() ];
		$titleCondText = $dbr->makeList( $titleCond, LIST_AND );

		$idCond = [ 'thread_article_id' => $id ];
		$idCondText = $dbr->makeList( $idCond, LIST_AND );

		$fixTitleCond = [ $idCondText, "NOT ($titleCondText)" ];
		$fixIdCond = [ $titleCondText, "NOT ($idCondText)" ];

		// Try to hit the most recent threads first.
		$options = [ 'LIMIT' => 500, 'ORDER BY' => 'thread_id DESC' ];

		// Batch in 500s
		if ( $limit ) {
			$options['LIMIT'] = min( $limit, 500 );
		}

		$rowsAffected = 0;
		$roundRowsAffected = 1;

		while ( ( !$limit || $rowsAffected < $limit ) && $roundRowsAffected > 0 ) {
			$roundRowsAffected = 0;

			// Fix wrong title.
			$fixTitleCount = $dbr->selectField( 'thread', 'COUNT(*)', $fixTitleCond, __METHOD__ );
			if ( intval( $fixTitleCount ) ) {
				$dbw->update( 'thread', $titleCond, $fixTitleCond, __METHOD__, $options );
				$roundRowsAffected += $dbw->affectedRows();
			}

			// Fix wrong ID
			$fixIdCount = $dbr->selectField( 'thread', 'COUNT(*)', $fixIdCond, __METHOD__ );
			if ( intval( $fixIdCount ) ) {
				$dbw->update( 'thread', $idCond, $fixIdCond, __METHOD__, $options );
				$roundRowsAffected += $dbw->affectedRows();
			}

			$rowsAffected += $roundRowsAffected;
		}

		if ( $limit && ( $rowsAffected >= $limit ) && $queueMore ) {
			$jobParams = [ 'limit' => $limit, 'cascade' => true ];
			JobQueueGroup::singleton()->push(
				new SynchroniseThreadArticleDataJob(
					$article->getTitle(),
					$jobParams
				)
			);
		}

		return $limit ? ( $rowsAffected < $limit ) : true;
	}
}
