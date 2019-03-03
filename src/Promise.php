<?php

namespace dirtsimple\imposer;

use dirtsimple\fn;
use GuzzleHttp\Promise as GP;

class Promise {

	# Return a checked promise for $data, defaulting to throw handler
	static function checked($data, $handler=null) {
		return CheckedPromise::wrap($data, $handler);
	}

	static function deferred_throw($reason) {
		GP\queue()->add(function() use ($reason) {
			throw GP\exception_for($reason);
		});
	}
}