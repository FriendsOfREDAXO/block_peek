<?php

/** @var rex_addon_interface $this */

// Remove preview cache entries written in older formats (symfony/cache trees,
// one-file-per-version JSON). They'd never be read again.
rex_dir::delete($this->getCachePath());
