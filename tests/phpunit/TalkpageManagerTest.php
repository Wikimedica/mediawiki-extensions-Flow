<?php

namespace Flow\Tests;

use Title;
use User;
use WikiPage;
use WikitextContent;

use Flow\Container;

/**
 * @group Flow
 * @group Database
 */
class TalkpageManagerTest extends \MediaWikiTestCase {
	/**
	 * @var TalkpageManager
	 */
	protected $talkpageManager;

	public function __construct() {
		$this->talkpageManager = Container::get( 'occupation_controller' );

		$this->tablesUsed = array_merge( $this->tablesUsed, [
			'page',
			'revision',
		] );
	}

	public function testCheckIfCreationIsPossible() {
		$this->setMwGlobals( 'wgContentHandlerUseDB', false );

		$useDbFalseStatus = $this->talkpageManager->checkIfCreationIsPossible( Title::newFromText( 'Earth' ), true );
		$this->assertTrue( $useDbFalseStatus->hasMessage( 'flow-error-allowcreation-no-usedb' ), 'Error for wrong $wgContentHandlerUseDB setting' );
		$this->assertFalse( $useDbFalseStatus->isOK(), 'Error for wrong $wgContentHandlerUseDB setting' );

		$this->setMwGlobals( 'wgContentHandlerUseDB', true );

		$existentTitle = Title::newFromText( 'Exists' );
		$status = WikiPage::factory( $existentTitle )
			->doEditContent(
				new WikitextContent( 'This exists' ),
				"with an edit summary"
			);
		if ( !$status->isGood() ) {
			$this->fail( $status->getMessage()->plain() );
		}

		$existTrueStatus = $this->talkpageManager->checkIfCreationIsPossible( $existentTitle, /*mustNotExist*/ true );
		$this->assertTrue( $existTrueStatus->hasMessage( 'flow-error-allowcreation-already-exists' ), 'Error when page already exists and mustNotExist true was passed' );
		$this->assertFalse( $existTrueStatus->isOK(), 'Error when page already exists and mustNotExist true was passed' );

		$existFalseStatus = $this->talkpageManager->checkIfCreationIsPossible( $existentTitle, /*mustNotExist*/ false );
		$this->assertFalse( $existFalseStatus->hasMessage( 'flow-error-allowcreation-already-exists' ), 'No error when page already exists and mustNotExist false was passed' );
		$this->assertTrue( $existFalseStatus->isOK(), 'No error when page already exists and mustNotExist false was passed' );
	}

	public function testCheckIfUserHasPermission() {
		global $wgNamespaceContentModels;

		$tempModels = $beforeModels = $wgNamespaceContentModels;
		$tempModels[NS_USER_TALK] = CONTENT_MODEL_FLOW_BOARD;

		$unconfirmedUser = User::newFromName( 'UTFlowUnconfirmed' );

		$this->setMwGlobals( 'wgNamespaceContentModels', $tempModels );

		$permissionStatus = $this->talkpageManager->checkIfUserHasPermission( Title::newFromText( 'User talk:Test123' ), $unconfirmedUser );
		$this->assertTrue( $permissionStatus->isOK(), 'No error if user checks permissions for enabling Flow board in default-Flow namespace' );

		$permissionStatus = $this->talkpageManager->checkIfUserHasPermission( Title::newFromText( 'User:Test123' ), $unconfirmedUser );
		$this->assertFalse( $permissionStatus->isOK(), 'Error if user without flow-create-board enabling Flow board in default-Flow namespace' );
		$this->assertTrue( $permissionStatus->hasMessage( 'flow-error-allowcreation-flow-create-board' ), 'Error if user without flow-create-board enabling Flow board in default-Flow namespace' );

		$adminUser = $this->getMockBuilder( 'User' )
			->setMethods( array( 'isAllowed' ) )
			->getMock();

		// Set up the expectation for the update() method
		// to be called only once and with the string 'something'
		// as its parameter.
		$adminUser->expects( $this->once() )
			->method( 'isAllowed' )
			->with( $this->equalTo( 'flow-create-board' ) )
			->will( $this->returnValue( true ) );

		$permissionStatus = $this->talkpageManager->checkIfUserHasPermission( Title::newFromText( 'User:Test123' ), $adminUser );
		$this->assertTrue( $permissionStatus->isOK(), 'No if user without flow-create-board enabling Flow board in default-Flow namespace' );
	}
}
