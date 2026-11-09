<?php

define('PUSHER_APP_ID', 'TU_APP_ID');
define('PUSHER_KEY', 'TU_KEY');
define('PUSHER_SECRET', 'TU_SECRET');
define('PUSHER_CLUSTER', 'us2');

function trigger_realtime_event($channel, $event, $data) {
    /* 
    Descomenta este bloque cuando tengas Pusher configurado:
    
    require __DIR__ . '/vendor/autoload.php';
    $options = array(
        'cluster' => PUSHER_CLUSTER,
        'useTLS' => true
    );
    $pusher = new Pusher\Pusher(
        PUSHER_KEY,
        PUSHER_SECRET,
        PUSHER_APP_ID,
        $options
    );
    $pusher->trigger($channel, $event, $data);
    */
}
?>