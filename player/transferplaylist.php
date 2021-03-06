<?php
chdir('..');
include ("includes/vars.php");
include ("includes/functions.php");
include ("international.php");
include ("collection/collection.php");
include ("player/mpd/connection.php");
include ("backends/sql/backend.php");
require_once ("utils/imagefunctions.php");

$json = json_decode(file_get_contents("php://input"), true);
$tracks = array();
debuglog("Transferring Playlist From ".$prefs['currenthost']." to ".$json['currenthost'], "TRANSFER", 5);
debuglog("  Reading Current Playlist From ".$prefs['currenthost'],"TRANSFER");

// Read Playlist From Current Player
doCollection("playlistinfo");
$mpd_status = do_mpd_command ("status", true, false);
do_mpd_command('stop');
close_mpd();
$cmds = array();
$cmds[] = 'stop';
$cmds[] = 'clear';
foreach ($tracks as $track) {
    array_push($cmds, join_command_string(array('add', $track['uri'])));
}
$convert_slave = $prefs['mopidy_slave'];
$collection_type = $prefs['player_backend'];

// Connect To new Player and check its type
$prefs['currenthost'] = $json['currenthost'];
set_player_connect_params();
debuglog("  Opening Connection To ".$prefs['currenthost']);
@open_mpd_connection();
probe_player_type();

if ($convert_slave && !$prefs['mopidy_slave']) {
    // Convert back from file:// URIs to local:track: URIs
    $cmds = check_reverse_slave_actions($cmds);
} else if (!$convert_slave && $prefs['mopidy_slave']) {
    // Convert from local:track: URIs to file:// URIs
    $cmds = check_slave_actions($cmds);
}
// Convert Mopidy/MPD URIs. Note this only supports converting from
// local:track: URIs, so the above statements take care of that
$cmds = check_player_type_actions($cmds, $collection_type);
do_mpd_command_list($cmds);

// Work around Mopidy bug where it doesn't update the 'state' variable properly
// after a seek and so doing all this in one command list doesn't work

debuglog("  State is ".$mpd_status['state'],"TRANSFER");
if (array_key_exists('state', $mpd_status) && $mpd_status['state'] == 'play') {
    do_mpd_command(join_command_string(array('play', $mpd_status['song'])));
    wait_for_player_state('play');
    if ($tracks[$mpd_status['song']]['type'] != 'stream') {
        do_mpd_command(join_command_string(array('seek', $mpd_status['song'], intval($mpd_status['elapsed']))));
    }
}
close_mpd();

header('HTTP/1.1 204 No Content');

function doNewPlaylistFile(&$filedata) {
    global $tracks;
    debuglog("    Track ".$filedata['Pos']." ".$filedata['type']." ".$filedata['file'], "TRANSFER");
    array_push($tracks, array('type' => $filedata['type'], 'uri' => $filedata['file']));
}

?>
