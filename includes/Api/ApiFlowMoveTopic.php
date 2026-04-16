<?php

namespace Flow\Api;

use Wikimedia\ParamValidator\ParamValidator;

class ApiFlowMoveTopic extends ApiFlowBasePost {

	public function __construct( $api, $modName ) {
		parent::__construct( $api, $modName, 'mt' );
	}

	protected function getBlockParams() {
		$params = $this->extractRequestParams();
		return [
			'topic' => $params,
		];
	}

	protected function getAction() {
		return 'move-topic';
	}

	public function getAllowedParams() {
		return [
			'destination' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'reason' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
		] + parent::getAllowedParams();
	}

	protected function getExamplesMessages() {
		return [
			'action=flow&submodule=move-topic&page=Topic:S2tycnas4hcucw8w&mtdestination=Talk:NewBoard&mtreason=Reorganization'
				=> 'apihelp-flow+move-topic-example-1',
		];
	}
}
