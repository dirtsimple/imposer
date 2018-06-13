<?php

namespace dirtsimple\imposer;

class Resource extends Task {

	protected static $instances=array();

	function steps() { $this->error("Resources can't have steps"); }
	function reads() { $this->error("Resources don't read state data"); }
}
