<?php
declare(strict_types=1);
namespace Glued\Lib;

use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Routing\RouteContext;
use Glued\Lib\Exceptions\DbException;

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
     * @return string             Replaced string
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
     * get_routes() will
     * - get slim routes from the route collector and
     * - append the pattern and implemented methods
     * - append the route name
     * - append the label (from configuration)
     * and return all this as an indexed array.
     * @return array [description]
     */
    public function get_routes($currentRoute = null): array {
        $routes = $this->routecollector->getRoutes();
        foreach ($routes as $route) {
            $i = $route->getPattern();
            $data[$i]['pattern'] = $route->getPattern();
            $data[$i]['methods'] = array_merge($data[$i]['methods'] ?? [], $route->getMethods());
            if ($route->getName()) { $data[$i]['name'] = $route->getName(); }
            if ($data[$i]['name'] === $currentRoute) { $data[$i]['current'] = true; }
            if ($data[$i]['name'] != false) {
                try {
                    $data[$i]['url'] = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->routecollector->getRouteParser()->urlFor($data[$i]['name']);
                } catch (\InvalidArgumentException $e) { $data[$i]['argsrequired'] = true; }
                $data[$i]['label'] = $this->settings['routes'][$data[$i]['name']]['label'] ?? null;
                $data[$i]['service'] = $this->settings['routes'][$data[$i]['name']]['service'] ?? null;
                $data[$i]['dscr'] = $this->settings['routes'][$data[$i]['name']]['dscr'] ?? null;
            }
        }
        ksort($data, SORT_NATURAL);
        return array_values($data);
    }

    ////////////////////////////////////////////////////////////////////
    // SQL HELPERS                                                    //
    ////////////////////////////////////////////////////////////////////

    /**
     * Validates and transforms valid (GET) request parameters to JSON paths.
     * https://server/api?Param_sub-param=value => param.sub-param
     * @param string $path Request parameter transformed to a JSON path if valid.
     * Transformation replaces _ with . and converts all uppercase characters to lowercase.
     * @return bool true when $path is valid, false when invalid. Invalid $path
     * starts or ends with _, -, - contains __, --, or any other characters except a-z 0-9.
     */
    public function reqParamToJsonPath(string &$path): bool {
        // convert key to lowercase
        $path = strtolower($path);

        // https://regex101.com/
        // ensure key doesn't start or end with _, doesn't contain __,
        // and consists of lowercase alphanumeric characters _, and -.
        if (preg_match("/^(?!_)(?!.*__)[a-z0-9_-]+(?<!_)$/", $path) !== 1) { return false; }

        // split by _ into elements
        $arr = explode("_", $path);

        // ensure key doesn't start or end with _, doesn't contain __,
        // and consists of lowercase alphanumeric characters _, and -.
        foreach ($arr as $item) {
            if (preg_match("/^(?!-)(?!.*--)[a-z0-9-]+(?<!-)$/", $item) !== 1) { return false; }
        }

        $path = implode(".", $arr);
        return true;
    }

    /**
     * Constructs a SQL query against Glued's standard mysql collections with a minimal set of columns:
     * c_uuid - binary stored uuid with elements swapped for optimized storage, see `true` in `uuid_to_bin(? , true)`
     * c_data - the json data blob
     * c_stor_name - human-readable name for the object when accessed by the stor microservice.
     * The $qstring (i.e. SELECT) passed by reference will be appended by WHERE clauses constructed according to
     * $reqparams and $wheremods and finally enveloped by (changed into a subquery of) json_arrayagg().
     * This allows to return the whole response from mysql as a json string without further transformation of the data
     * in app logic.
     * @param array $reqparams (GET) request parameters that are converted them to SQL WHERE clauses
     * @param QueryBuilder $qstring Base SQL query string (i.e. select) passed as a reference to a QueryBuilder object.
     * @param array $qparams (GET) request parameter values to be used in WHERE clauses
     * @param array $wheremods $reqparam WHERE query modifiers. Since the JSON path of the parsed $reqparam vary,
     * a similar variability needs to be represented in the relevant WHERE query subelements.
     * @return void
     */
    function mysqlJsonQueryFromRequest(array $reqparams = [], QueryBuilder &$qstring, array &$qparams, array $wheremods = []) {

        // define fallback where modifier for the 'uuid' reqparam.
        if (!array_key_exists('uuid', $wheremods)) {
            $wheremods['uuid'] = 'c_uuid = uuid_to_bin( ? , true)';
        }

        foreach ($reqparams as $key => $val) {
            // if request parameter name ($key) doesn't validate, skip to next
            // foreach item, else replace _ with . in $key to get a valid jsonpath
            if ($this->reqParamToJsonPath($key) === false) { continue; }

            // default where construct that transposes https://server/endpoint?mykey=myval
            // to sql query substring `where (`c_data`->>"$.mykey" = ?)`
            $w = '(`c_data`->>"$.'.$key.'" = ?)';
            foreach ($wheremods as $wmk => $wmv) {
                if ($key === $wmk) { $w = $wmv; }
            }

            if (is_array($val)) {
                foreach ($val as $v) {
                    $qstring->where($w);
                    $qparams[] = $v;
                }
            } else {
                $qstring->where($w);
                $qparams[] = $val;
            }
        }
        // envelope in json_arrayagg to return a single row with the complete result
        $qstring = "select json_arrayagg(res_rows) from ( $qstring ) as res_json";
    }

    /**
     * Creates a metadata json header and appends $jsondata to path $.data (if $dataitem is kept default)
     * $jsondata would be typically acquired from db->rawQuery($qs, $qp) with the $qs and $gp parameters constructed
     * by mysqlJsonQueryFromRequest. Returns a PSR Response.
     * @param Response $response
     * @param array $jsondata
     * @param string $dataitem
     * @return Response
     */
    public function mysqlJsonResponse(Response $response, array $jsondata = [], string $dataitem = 'data'): Response {
        // construct the response metadata json, remove last character (closing curly bracket)
        $meta['service']   = basename(__ROOT__);
        $meta['timestamp'] = microtime();
        $meta['code']      = 200;
        $meta['message']   = 'OK';
        $meta = json_encode($meta, JSON_FORCE_OBJECT);
        $meta = mb_substr($meta, 0, -1);

        // get the json from a json_arrayagg() response
        $key = array_keys($jsondata[0])[0];
        $jsondata = $jsondata[0][$key];
        if (is_null($jsondata)) { $jsondata = '{}'; }

        // write the response body
        $body = $response->getBody();
        $body->write($meta.', "'.$dataitem.'": '.$jsondata."}");
        return $response->withBody($body)->withStatus(200)->withHeader('Content-Type', 'application/json');
    }



    ////////////////////////////////////////////////////////////////////
    // CURL HELPERS                                                   //
    ////////////////////////////////////////////////////////////////////

    public function fetch_uri($uri, $extra_opts = []) {
        $curl_handle = curl_init();
        $extra_opts[CURLOPT_URL] = $uri; 
        $curl_options = array_replace( $this->settings['php']['curl'], $extra_opts );
        curl_setopt_array($curl_handle, $curl_options);
        $data = curl_exec($curl_handle);
        curl_close($curl_handle);
        return $data;
    }





}
