<?php

namespace dirtsimple\imposer;

use dirtsimple\fn;
use GuzzleHttp\Promise as GP;

class Promise {

	# Return a watched promise for $data, defaulting to throw handler
	static function value($val, $handler=null) {
		return WatchedPromise::wrap($val, $handler);
	}

	static function deferred_throw($reason) {
		GP\queue()->add(function() use ($reason) {
			throw GP\exception_for($reason);
		});
	}
}