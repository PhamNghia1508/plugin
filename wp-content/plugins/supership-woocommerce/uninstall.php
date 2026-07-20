<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Shipment metadata and settings are intentionally retained to preserve order history.
delete_option( 'spx_tracking_sync_lock' );
