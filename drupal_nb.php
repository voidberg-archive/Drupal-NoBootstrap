<?php
function drupal_config($conf = NULL) {
  static $config_file = NULL;

  if ($conf) {
    $config_file = $conf;
  }

  return $config_file;
}

function mysql_parse_config() {
  $config_file = drupal_config();

  if (!$config_file) {
    return NULL;
  }

  @require $config_file;

  if (is_array($db_url)) {
    $url = parse_url($db_url['default']);
  }
  else {
    $url = parse_url($db_url);
  }

  $url['user'] = urldecode($url['user']);
  $url['pass'] = isset($url['pass']) ? urldecode($url['pass']) : '';
  $url['host'] = urldecode($url['host']);
  $url['path'] = urldecode($url['path']);
  $url['path'] = substr($url['path'], 1);

  if (isset($url['port'])) {
    $url['host'] = $url['host'] .':'. $url['port'];
  }
  return $url;
}

function connect() {
  $conf = mysql_parse_config();
  if (!$conf) {
    return FALSE;
  }

  $connection = mysql_connect($conf['host'], $conf['user'], $conf['pass'], TRUE, 2);
  if (!$connection || !mysql_select_db($conf['path'])) {
    return FALSE;
  }
  mysql_query("SET NAMES 'utf8'");
  return TRUE;
}

function drupal_json($var = NULL) {
  // We are returning JavaScript, so tell the browser.
  header('Content-Type: text/javascript; charset=utf-8');

  if (isset($var)) {
    echo drupal_to_js($var);
  }
}

function drupal_to_js($var) {
  switch (gettype($var)) {
    case 'boolean':
      return $var ? 'true' : 'false'; // Lowercase necessary!
    case 'integer':
    case 'double':
      return $var;
    case 'resource':
    case 'string':
      return '"'. str_replace(array("\r", "\n", "<", ">", "&"),
                              array('\r', '\n', '\x3c', '\x3e', '\x26'),
                              addslashes($var)) .'"';
    case 'array':
      // Arrays in JSON can't be associative. If the array is empty or if it
      // has sequential whole number keys starting with 0, it's not associative
      // so we can go ahead and convert it as an array.
      if (empty ($var) || array_keys($var) === range(0, sizeof($var) - 1)) {
        $output = array();
        foreach ($var as $v) {
          $output[] = drupal_to_js($v);
        }
        return '[ '. implode(', ', $output) .' ]';
      }
      // Otherwise, fall through to convert the array as an object.
    case 'object':
      $output = array();
      foreach ($var as $k => $v) {
        $output[] = drupal_to_js(strval($k)) .': '. drupal_to_js($v);
      }
      return '{ '. implode(', ', $output) .' }';
    default:
      return 'null';
  }
}

function variable_get($name, $default) {
  global $variables;

  if ($variables[$name]) {
    return $variables[$name];
  }

  $row = mysql_fetch_object(mysql_query(sprintf('SELECT value FROM variable WHERE name = "%s"', mysql_real_escape_string($name))));
  if ($row) {
    $value = unserialize($row->value);
    $variables[$name] = $value;
    return $value;
  }
  else {
    return $default;
  }
}

function variable_set($name, $value) {
  global $variables;

  $serialized_value = serialize($value);
  mysql_query(sprintf("UPDATE variable SET value = '%s' WHERE name = '%s'", mysql_real_escape_string($serialized_value), mysql_real_escape_string($name)));
  if (!mysql_affected_rows()) {
    @mysql_query(sprintf("INSERT INTO variable (name, value) VALUES ('%s', '%s')", mysql_real_escape_string($name), mysql_real_escape_string($serialized_value)));
  }

  $conf[$name] = $value;
}

function dsm($input, $name = NULL) {
  dpm($input, $name);
}

function dpm($input, $name = NULL) {
  $export = print_r($input, TRUE);
  print '<pre>' . $export . '</pre>';
}

function drupal_substr($text, $start, $length = NULL) {
  global $multibyte;
  if ($multibyte == UNICODE_MULTIBYTE) {
    return $length === NULL ? mb_substr($text, $start) : mb_substr($text, $start, $length);
  }
  else {
    $strlen = strlen($text);
    // Find the starting byte offset
    $bytes = 0;
    if ($start > 0) {
      // Count all the continuation bytes from the start until we have found
      // $start characters
      $bytes = -1;
      $chars = -1;
      while ($bytes < $strlen && $chars < $start) {
        $bytes++;
        $c = ord($text[$bytes]);
        if ($c < 0x80 || $c >= 0xC0) {
          $chars++;
        }
      }
    }
    else if ($start < 0) {
      // Count all the continuation bytes from the end until we have found
      // abs($start) characters
      $start = abs($start);
      $bytes = $strlen;
      $chars = 0;
      while ($bytes > 0 && $chars < $start) {
        $bytes--;
        $c = ord($text[$bytes]);
        if ($c < 0x80 || $c >= 0xC0) {
          $chars++;
        }
      }
    }
    $istart = $bytes;

    // Find the ending byte offset
    if ($length === NULL) {
      $bytes = $strlen - 1;
    }
    else if ($length > 0) {
      // Count all the continuation bytes from the starting index until we have
      // found $length + 1 characters. Then backtrack one byte.
      $bytes = $istart;
      $chars = 0;
      while ($bytes < $strlen && $chars < $length) {
        $bytes++;
        $c = ord($text[$bytes]);
        if ($c < 0x80 || $c >= 0xC0) {
          $chars++;
        }
      }
      $bytes--;
    }
    else if ($length < 0) {
      // Count all the continuation bytes from the end until we have found
      // abs($length) characters
      $length = abs($length);
      $bytes = $strlen - 1;
      $chars = 0;
      while ($bytes >= 0 && $chars < $length) {
        $c = ord($text[$bytes]);
        if ($c < 0x80 || $c >= 0xC0) {
          $chars++;
        }
        $bytes--;
      }
    }
    $iend = $bytes;

    return substr($text, $istart, max(0, $iend - $istart + 1));
  }
}
