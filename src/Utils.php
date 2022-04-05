<?php
declare(strict_types=1);
namespace Glued\Lib;

use Exception;
use Slim\Routing\RouteContext;

class Utils
{

    protected $db;
    protected $settings;
    protected $routecollector;

    public function __construct($db, $settings, $routecollector) {
        $this->db = $db;
        $this->settings = $settings;
        $this->routecollector = $routecollector;
    }


    ////////////////////////////////////////////////////////////////////
    // ARRAYS MANIPULTAION                                            //
    ////////////////////////////////////////////////////////////////////

    public function array_unflatten($collection) {
        $collection = (array) $collection;
        $output = array();
        foreach ($collection as $key => $value) {
            $this->array_set( $output, $key, $value );
            if (is_array($value) && !strpos($key, '.')) {
                $nested = array_unflatten( $value );
                $output[$key] = $nested;
            }
        }
        return $output;
    }

    public function array_set(&$array, $key, $value)  {
        if (is_null($key)) { return $array = $value; }
        $keys = explode( '.', $key );
        while (count($keys) > 1) {
            $key = array_shift( $keys );
            // If the key doesn't exist at this depth, we will just create an empty array
            // to hold the next value, allowing us to create the arrays to hold final
            // values at the correct depth. Then we'll keep digging into the array.
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = array();
            }
            $array =& $array[$key];
        }
        $array[array_shift($keys)] = $value;
        return $array;
    }


    ////////////////////////////////////////////////////////////////////
    // STRINGS MANIPULTAION                                           //
    ////////////////////////////////////////////////////////////////////

    /**
     * Replaces the nth occurrence of a substring in a string
     * 
     * @param string $search      Search string
     * @param string $replace     Replace string
     * @param string $subject     Source string
     * @param int $occurrence     N-th occurrence
     * @return type               Replaced string
     */
    public function str_replace_n($search, $replace, $subject, $occurrence): string {
        $search = preg_quote($search);
        return preg_replace("/^((?:(?:.*?$search){".--$occurrence."}.*?))$search/", "$1$replace", $subject);
    }

    /**
     * Concatenates items into a string using a delimeter.
     * Excessive delimeters get trimmed.
     * 
     * @param string $delimeter     The delimeter
     * @param array $arrayOfStrings Tha array to be concatenated
     * @return string               Concatenated string
     */
    public function concat($delimeter, array $arrayOfStrings): string {
        return trim(implode($delimeter, array_filter(array_map('trim',$arrayOfStrings))));
    }


    ////////////////////////////////////////////////////////////////////
    // LOCALIZATION                                                   //
    ////////////////////////////////////////////////////////////////////

    /**
     * Converts the two-letter language code into the default locale.
     * @param string $language ISO 639-1 Code (i.e. `en`)
     * @return string The most common locale associated with $language (i.e. `en_US`)
     */
    public function get_default_locale(string $language): string {
        $def_locales = [
            "af" => "af_ZA",
            "ar" => "ar",
            "bg" => "bg_BG",
            "ca" => "ca_AD",
            "cs" => "cs_CZ",
            "cy" => "cy_GB",
            "da" => "da_DK",
            "de" => "de_DE",
            "el" => "el_GR",
            "en" => "en_US",
            "es" => "es_ES",
            "et" => "et_EE",
            "eu" => "eu",
            "fa" => "fa_IR",
            "fi" => "fi_FI",
            "fr" => "fr_FR",
            "he" => "he_IL",
            "hi" => "hi_IN",
            "hr" => "hr_HR",
            "hu" => "hu_HU",
            "id" => "id_ID",
            "is" => "is_IS",
            "it" => "it_IT",
            "ja" => "ja_JP",
            "km" => "km_KH",
            "ko" => "ko_KR",
            "la" => "la",
            "lt" => "lt_LT",
            "lv" => "lv_LV",
            "mn" => "mn_MN",
            "nb" => "nb_NO",
            "nl" => "nl_NL",
            "nn" => "nn_NO",
            "pl" => "pl_PL",
            "pt" => "pt_PT",
            "ro" => "ro_RO",
            "ru" => "ru_RU",
            "sk" => "sk_SK",
            "sl" => "sl_SI",
            "sr" => "sr_RS",
            "sv" => "sv_SE",
            "th" => "th_TH",
            "tr" => "tr_TR",
            "uk" => "uk_UA",
            "vi" => "vi_VN",
            "zh" => "zh_CN"
        ];
        if (isset($def_locales[$language])) { return $def_locales[$language]; }
        else { return 'en_US'; }

    }


    ////////////////////////////////////////////////////////////////////
    // SLIM ROUTING HELPERS                                           //
    ////////////////////////////////////////////////////////////////////

    public function get_current_route($request): string {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        return $route->getName();
    }

    /**
     * get_routes_array() will
     * - get slim routes from the route collector and
     * - append the pattern and implemented methods
     * - append the route name
     * - append the label and icon (from configuration)
     * and return all this as an indexed array.
     * @return array [description]
     */
    public function get_routes_array(): array {
        $routes = $this->routecollector->getRoutes();
        foreach ($routes as $route) {
            $i = $route->getPattern();
            $data[$i]['pattern'] = $route->getPattern();
            $data[$i]['methods'] = array_merge($data[$i]['methods'] ?? [], $route->getMethods());
            if ($route->getName()) $data[$i]['name'] = $route->getName();
            if ($data[$i]['name'] != false) {
                try {
                    $data[$i]['url'] = $this->routecollector->getRouteParser()->urlFor($data[$i]['name']);
                } catch (\InvalidArgumentException $e) { $data[$i]['argsrequired'] = true; }
                $data[$i]['label'] = $this->settings['routes'][$data[$i]['name']]['label'] ?? null;
                $data[$i]['icon'] = $this->settings['routes'][$data[$i]['name']]['icon'] ?? null;
            } 
        }
        ksort($data, SORT_NATURAL);
        return array_values($data);
    }

    /**
     * get_routes_map() turns the indexed array into an associative array (map).
     * The key is the route name. get_routes_map() is used by get_routes_tree().
     * @return array [description]
     */
    private function get_routes_map(): array {
        $routes = $this->get_routes_array();
        foreach ($routes as $route) {
            if ($route['name']) { $res[$route['name']] = $route; }
        }
        return $res;
    }

    /**
     * get_routes_tree() returns routes as the leafs of a 3 dimensional array.
     * 2nd dimension nodes group routes of one microservice.
     * 1st dimension nodes group routes according to usage (interface, api, backend)
     * @return array [description]
     */
    public function get_routes_tree($current_route = null): array {

        $routes = $this->get_routes_map();
        foreach ($routes as $k => $v) {
            // 3rd tier nodes (route within a microservice)
            $path = explode( '_', $k);
            $item = $v;
            if ($k === $current_route) { 
                $item['current'] = true; 
                $append[$path[0]]['children'][$path[1]]['node']['current'] = true;
                $append[$path[0]]['node']['current'] = true;
            }
            $item['name'] = $k;
            $item['type'] = 'route';
            $r[$path[0]]['children'][$path[1]]['children'][]['node'] = $item;
            // 2nd tier nodes (routegroup of a microservice: i.e. core, skeleton ...)
            $item = null;
            $item['label'] = $this->settings['routes'][$path[0].'_'.$path[1]]['label'] ?? null;
            $item['icon'] = $this->settings['routes'][$path[0].'_'.$path[1]]['icon'] ?? null;
            $item['type'] = 'routegroup';
            $r[$path[0]]['children'][$path[1]]['node'] = $item;
            // 1st tier nodes (routegroup class: app, api)
            $item['label'] = $this->settings['routes'][$path[0]]['label'] ?? null;
            $item['icon'] = $this->settings['routes'][$path[0]]['icon'] ?? null;
            $item['type'] = 'routegroup';
            $r[$path[0]]['node'] = $item;
        }
        $r = array_merge_recursive($r, $append);
        return $r;
    }


    ////////////////////////////////////////////////////////////////////
    // SQL HELPERS                                                    //
    ////////////////////////////////////////////////////////////////////

    public function sql_insert_with_json($table, $row) {
        $this->db->startTransaction(); 
        $id = $this->db->insert($table, $row);
        $err = $this->db->getLastErrno();
        if ($id) {
          $updt = $this->db->rawQuery("UPDATE `".$table."` SET `c_json` = JSON_SET(c_json, '$.id', ?) WHERE c_uid = ?", Array ((int)$id, (int)$id));
          $err += $this->db->getLastErrno();
        }
        if ($err === 0) { $this->db->commit(); } else { $this->db->rollback(); throw new \Exception(__('Database error: ')." ".$err." ".$this->db->getLastError()); }
        return (int)$id;
    }


    ////////////////////////////////////////////////////////////////////
    // CURL HELPERS                                                   //
    ////////////////////////////////////////////////////////////////////

    public function fetch_uri($uri, $extra_opts = []) {
        $curl_handle = curl_init();
        $extra_opts[CURLOPT_URL] = $uri; 
        $curl_options = array_replace( $this->settings['curl'], $extra_opts );
        curl_setopt_array($curl_handle, $curl_options);
        $data = curl_exec($curl_handle);
        curl_close($curl_handle);
        return $data;
    }





}
