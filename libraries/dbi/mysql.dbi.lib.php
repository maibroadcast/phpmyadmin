<?php
/* $Id$ */
// vim: expandtab sw=4 ts=4 sts=4:

/**
 * Interface to the classic MySQL extension
 */

/**
 * Loads the mysql extensions if it is not loaded yet
 */
if (!@function_exists('mysql_connect')) {
    PMA_dl('mysql');
}

// check whether mysql is available
if (!@function_exists('mysql_connect')) {
    require_once('./libraries/header_http.inc.php');
    echo sprintf($strCantLoad, 'mysql') . '<br />' . "\n"
         . '<a href="./Documentation.html#faqmysql" target="documentation">' . $GLOBALS['strDocu'] . '</a>' . "\n";
    exit;
}

// MySQL client API
if (!defined('PMA_MYSQL_CLIENT_API')) {
    $client_api = explode('.', mysql_get_client_info());
    define('PMA_MYSQL_CLIENT_API', (int)sprintf('%d%02d%02d', $client_api[0], $client_api[1], intval($client_api[2])));
    unset($client_api);
}

function PMA_DBI_connect($user, $password) {
    global $cfg, $php_errormsg;

    $server_port   = (empty($cfg['Server']['port']))
                   ? ''
                   : ':' . $cfg['Server']['port'];

    if (strtolower($cfg['Server']['connect_type']) == 'tcp') {
        $cfg['Server']['socket'] = '';
    }

    $server_socket = (empty($cfg['Server']['socket']))
                   ? ''
                   : ':' . $cfg['Server']['socket'];

    if (PMA_MYSQL_CLIENT_API >= 32349) {
        $client_flags = $cfg['Server']['compress'] && defined('MYSQL_CLIENT_COMPRESS') ? MYSQL_CLIENT_COMPRESS : 0;
    }

    if (empty($client_clags)) {
        $connect_func = 'mysql_' . ($cfg['PersistentConnections'] ? 'p' : '') . 'connect';
        $link = @$connect_func($cfg['Server']['host'] . $server_port . $server_socket, $user, $password);
    } else {
        if ($cfg['PersistentConnections']) {
            $link = @mysql_pconnect($cfg['Server']['host'] . $server_port . $server_socket, $user, $password, $client_flags);
        } else {
            $link = @mysql_connect($cfg['Server']['host'] . $server_port . $server_socket, $user, $password, FALSE, $client_flags);
        }
    }

    if (empty($link)) {
        PMA_auth_fails();
    } // end if

    if (!defined('PMA_MYSQL_INT_VERSION')) {
        $result = mysql_query('SELECT VERSION() AS version', $link);
        if ($result != FALSE && @mysql_num_rows($result) > 0) {
            $row   = mysql_fetch_row($result);
            $match = explode('.', $row[0]);
            mysql_free_result($result);
        }
        if (!isset($row)) {
            define('PMA_MYSQL_INT_VERSION', 32332);
            define('PMA_MYSQL_STR_VERSION', '3.23.32');
        } else{
            define('PMA_MYSQL_INT_VERSION', (int)sprintf('%d%02d%02d', $match[0], $match[1], intval($match[2])));
            define('PMA_MYSQL_STR_VERSION', $row[0]);
            unset($result, $row, $match);
        }
    }

    if (PMA_MYSQL_INT_VERSION >= 40100) {
        mysql_query('SET CHARACTER SET utf8;', $link);
        mysql_query('SET SESSION character_set_connection = \'utf8\';', $link);
    } else {
        require_once('./libraries/charset_conversion.lib.php');
    }

    return $link;
}

function PMA_DBI_select_db($dbname, $link = '') {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    if (PMA_MYSQL_INT_VERSION < 40100) {
        $dbname = PMA_convert_charset($dbname);
    }
    return mysql_select_db($dbname, $link);
}

function PMA_DBI_try_query($query, $link = '') {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    if (PMA_MYSQL_INT_VERSION < 40100) {
        $query = PMA_convert_charset($query);
    }
    return mysql_query(PMA_convert_charset($query), $link);
}

// The following function is meant for internal use only.
// Do not call it from outside this library!
function PMA_mysql_fetch_array($result, $type = FALSE) {
    global $cfg, $allow_recoding, $charset, $convcharset;

    if ($type != FALSE) {
        $data = mysql_fetch_array($result, $type);
    } else {
        $data = mysql_fetch_array($result);
    }
    if (PMA_MYSQL_INT_VERSION >= 40100
        || !(isset($cfg['AllowAnywhereRecoding']) && $cfg['AllowAnywhereRecoding'] && $allow_recoding)) {
        /* No recoding -> return data as we got them */
        return $data;
    } else {
        $ret = array();
        $num = mysql_num_fields($result);
        $i = 0;
        for($i = 0; $i < $num; $i++) {
            $meta = mysql_fetch_field($result);
            $name = mysql_field_name($result, $i);
            if (!$meta) {
                /* No meta information available -> we guess that it should be converted */
                if (isset($data[$i])) $ret[$i] = PMA_convert_display_charset($data[$i]);
                if (isset($data[$name])) $ret[PMA_convert_display_charset($name)] = PMA_convert_display_charset($data[$name]);
            } else {
                /* Meta information available -> check type of field and convert it according to the type */
                if ($meta->blob || stristr($meta->type, 'BINARY')) {
                    if (isset($data[$i])) $ret[$i] = $data[$i];
                    if (isset($data[$name])) $ret[PMA_convert_display_charset($name)] = $data[$name];
                } else {
                    if (isset($data[$i])) $ret[$i] = PMA_convert_display_charset($data[$i]);
                    if (isset($data[$name])) $ret[PMA_convert_display_charset($name)] = PMA_convert_display_charset($data[$name]);
                }
            }
        }
        return $ret;
    }
}

function PMA_DBI_fetch_assoc($result) {
    return PMA_mysql_fetch_array($result, MYSQL_ASSOC);
}

function PMA_DBI_fetch_row($result) {
    return PMA_mysql_fetch_array($result, MYSQL_NUM);
}

function PMA_DBI_free_result($result) {
    return @mysql_free_result($result);
}

function PMA_DBI_getError($link = '') {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    $error = mysql_errno($link);
    if ($error && PMA_MYSQL_INT_VERSION >= 40100) {
        $error = '#' . ((string) $error) . ' - ' . mysql_error($link);
    } elseif ($error) {
        $error = '#' . ((string) $error) . ' - ' . PMA_convert_display_charset(mysql_error($link));
    }
    return $error;
}

function PMA_DBI_close($link = '') {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    return @mysql_close($link);
}

function PMA_DBI_num_rows($result) {
    return mysql_num_rows($result);
}

function PMA_DBI_insert_id($link) {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    return mysql_insert_id($link);
}

function PMA_DBI_affected_rows($link) {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    return mysql_affected_rows($link);
}

function PMA_DBI_get_fields_meta($result) {
    $fields       = array();
    $num_fields   = mysql_num_fields($result);
    for ($i = 0; $i < $num_fields; $i++) {
        $fields[] = mysql_fetch_field($result, $i);
    }
    return $fields;
}

function PMA_DBI_num_fields($result) {
    return mysql_num_fields($result);
}

?>
