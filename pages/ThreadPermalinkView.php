<?php

use MediaWiki\MediaWikiServices;

class ThreadPermalinkView extends LqtView {
	protected $thread;

	public function customizeNavigation( $skin, &$links ) {
		self::customizeThreadNavigation( $skin, $links, $this );
	}

	public static function customizeThreadNavigation( $skin, &$links, $view ) {
		$tempTitle = Title::makeTitle( NS_LQT_THREAD, 'A' );
		$talkKey = $tempTitle->getNamespaceKey( '' ) . '_talk';

		if ( !$view->thread ) {
			unset( $links['views']['edit'] );
			unset( $links['views']['history'] );

			$links['actions'] = [];

			unset( $links['namespaces'][$talkKey] );
			return;
		}

		// Insert 'article' and 'discussion' namespace-tabs
		$new_nstabs = self::getCustomTabs( $view );

		$nstabs =& $links['namespaces'];

		unset( $nstabs[$talkKey] );
		$nstabs = $new_nstabs + $nstabs;

		// Remove some views.
		$views =& $links['views'];
		unset( $views['viewsource'] );
		unset( $views['edit'] );

		$subpageTitle = $view->thread->title();
		// Re-point move, delete and history actions
		$actions =& $links['actions'];
		if ( isset( $actions['move'] ) && $subpageTitle ) {
			$subpage = $subpageTitle->getPrefixedText();
			$actions['move']['href'] =
				SpecialPage::getTitleFor( 'MoveThread', $subpage )->getLocalURL();
		}

		if ( isset( $actions['delete'] ) && $subpageTitle ) {
			$actions['delete']['href'] =
				$subpageTitle->getLocalURL( 'action=delete' );
		}

		if ( isset( $views['history'] ) ) {
			$views['history']['href'] =
				self::permalinkUrl( $view->thread, 'thread_history' );
			if ( $view->methodApplies( 'thread_history' ) ) {
				$views['history']['class'] = 'selected';
			}
		}
	}

	// Pre-generates the tabs to be included, for customizeNavigation
	// to insert in the appropriate place
	private static function getCustomTabs( $view ) {
		$tabs = [];

		$articleTitle = $view->thread->getTitle()->getSubjectPage();
		$talkTitle = $view->thread->getTitle()->getTalkPage();

		$articleClasses = [];
		if ( !$articleTitle->exists() ) {
			$articleClasses[] = 'new';
		}
		if ( $articleTitle->equals( $view->thread->getTitle() ) ) {
			$articleClasses[] = 'selected';
		}

		$talkClasses = [];
		if ( !$talkTitle->exists() ) {
			$talkClasses[] = 'new';
		}

		if ( wfMessage( $articleTitle->getNamespaceKey() )->exists() ) {
			$articleNamespaceText = wfMessage( $articleTitle->getNamespaceKey() )->text();
		} else {
			$articleNamespaceText = $articleTitle->getNsText();
		}

		$tabs['article'] =
			[
				'text' => $articleNamespaceText,
				'href' => $articleTitle->getLocalURL(),
				'class' => implode( ' ', $articleClasses ),
			];

		$tabs['lqt_talk'] =
			[
				// talkpage certainly exists since this thread is from it.
				'text' => wfMessage( 'talk' )->text(),
				'href' => $talkTitle->getLocalURL(),
				'class' => implode( ' ', $talkClasses ),
			];

		return $tabs;
	}

	public function showThreadHeading( $thread ) {
		parent::showThreadHeading( $thread );
	}

	public function noSuchRevision() {
		$this->output->addWikiMsg( 'lqt_nosuchrevision' );
	}

	public function showMissingThreadPage() {
		$this->output->setPageTitle( wfMessage( 'lqt_nosuchthread_title' ) );
		$this->output->addWikiMsg( 'lqt_nosuchthread' );
	}

	public function getSubtitle() {
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		$fragment = '#' . $this->anchorName( $this->thread );

		$talkpage = $this->thread->getTitle();
		$talkpage->setFragment( $fragment );
		$talkpage_link = $linkRenderer->makeLink( $talkpage );

		if ( $this->thread->hasSuperthread() ) {
			$topmostTitle = $this->thread->topmostThread()->title();
			$topmostTitle->setFragment( $fragment );

			$linkText = new HtmlArmor( wfMessage( 'lqt_discussion_link' )->parse() );
			$permalink = $linkRenderer->makeLink( $topmostTitle, $linkText );

			return wfMessage( 'lqt_fragment' )->rawParams( $permalink, $talkpage_link )->parse();
		} else {
			return wfMessage( 'lqt_from_talk' )->rawParams( $talkpage_link )->parse();
		}
	}

	public function __construct( &$output, &$article, &$title, &$user, &$request ) {
		parent::__construct( $output, $article, $title, $user, $request );

		$t = Threads::withRoot( $this->article );

		$this->thread = $t;
		if ( !$t ) {
			return;
		}

		// $this->article gets saved to thread_article, so we want it to point to the
		// subject page associated with the talkpage, always, not the permalink url.
		$this->article = $t->article(); # for creating reply threads.
	}

	public function show() {
		if ( !$this->thread ) {
			$this->showMissingThreadPage();
			return false;
		}

		if ( $this->request->getBool( 'lqt_inline' ) ) {
			$this->doInlineEditForm();
			return false;
		}

		// Handle action=edit stuff
		if ( $this->request->getVal( 'action' ) == 'edit' &&
				!$this->request->getVal( 'lqt_method', null ) ) {
			// Rewrite to lqt_method = edit
			$this->request->setVal( 'lqt_method', 'edit' );
			$this->request->setVal( 'lqt_operand', $this->thread->id() );
		}

		$topmostThreadTitle = $this->thread->topmostThread()->title();
		if ( $topmostThreadTitle ) {
			// Expose feed links.
			global $wgFeedClasses;
			$thread = $topmostThreadTitle->getPrefixedText();
			$apiParams = [
				'action' => 'feedthreads',
				'type' => 'replies|newthreads',
				'thread' => $thread
			];
			$urlPrefix = wfScript( 'api' ) . '?';
			foreach ( $wgFeedClasses as $format => $class ) {
				$theseParams = $apiParams + [ 'feedformat' => $format ];
				$url = $urlPrefix . wfArrayToCgi( $theseParams );
				$this->output->addFeedLink( $format, $url );
			}
		}

		$this->output->setSubtitle( $this->getSubtitle() );

		if ( $this->methodApplies( 'summarize' ) ) {
			$this->showSummarizeForm( $this->thread );
		} elseif ( $this->methodApplies( 'split' ) ) {
			$this->showSplitForm( $this->thread );
		}

		$this->showThread( $this->thread, 1, 1, [ 'maxDepth' => - 1, 'maxCount' => - 1 ] );

		$this->output->setPageTitle( $this->thread->subject() );
		return false;
	}
}
