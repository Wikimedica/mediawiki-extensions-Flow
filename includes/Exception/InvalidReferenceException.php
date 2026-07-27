<?php

namespace Flow\Exception;

/**
 * This is not logged, and must *only* be used for reference
 * errors caused by invalid (unprocessable) end-user input
 */
class InvalidReferenceException extends InvalidInputException {
	/**
	 * @var string Original constructor message. InvalidInputException replaces
	 *  getMessage() with a localized error page message, which is useless for
	 *  debug logging.
	 */
	private $debugMessage;

	/**
	 * @param string $message
	 * @param string $code
	 */
	public function __construct( $message, $code = 'default' ) {
		parent::__construct( $message, $code );
		$this->debugMessage = $message;
	}

	/**
	 * @return string
	 */
	public function getDebugMessage() {
		return $this->debugMessage;
	}
}
