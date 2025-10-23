<?php

echo rex_view::title(rex_i18n::msg('block_peek_title'));
echo rex_api_function::getMessage();
rex_be_controller::includeCurrentPageSubPath();
