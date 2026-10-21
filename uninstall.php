<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('simplemail_settings');
delete_option('simplemail_last_error');
