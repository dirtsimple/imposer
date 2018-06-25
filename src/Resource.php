<?php

namespace dirtsimple\imposer;

class Resource extends Task {

	function steps() {
		$this->error("Resources can't have steps");
	}

	function reads() {
		$this->error("Resources don't read specification data");
	}

	function isProducedBy() {
		foreach ( func_get_args() as $what ) $this->dependsOn[] = $this->task($what);
		return $this;
	}

}
