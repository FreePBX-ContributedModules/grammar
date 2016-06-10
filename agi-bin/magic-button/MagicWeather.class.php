<?php

  require_once("/var/lib/asterisk/agi-bin/magic-button/MagicHelpers.class.php");

class MagicWeather  {

  var $zip;
  // minutes between requests
  var $cachetime = 30;
  var $base = "/var/lib/asterisk/agi-bin/magic-button/data";
  var $ini_array;

  function MagicWeather($zip)  {
    $this->zip = $zip;
  }
  function check_cache($cache)  {
    $this->ini_array = @parse_ini_file($this->base."/$cache",true);
    if($this->ini_array[$this->zip] && (mktime()-$this->ini_array[$this->zip][cache]) < 901)  {
      return $this->ini_array[$this->zip];
    }
    else
      return false;
  }
  function get_current_conditions()  {
    if(!$cached_data = $this->check_cache("weather_cache_current"))  {
	$base_url = "http://forecast.weather.gov/";
	$url = $base_url."zipcity.php?inputstring=".$this->zip;
	$html = file_get_contents($url);

	if(preg_match("/<p class=\"current-conditions-location\">(.*)<\/p>/",$html,$regs))  {
                //$url = $base_url.$regs[1];
	        //$html = file_get_contents($url);
	
	        if(preg_match("/Point Forecast:<\/span>(.*)<br>/",$html,$regs))  {
                        $location = trim($regs[1]);
                        $location = $this->get_location(trim($regs[1]));
	        }

                if(preg_match("/<p class=\"myforecast-current-lrg\">(.*)<\/p>/", $html, $regs)) {
	        	$temperature = str_replace("&deg;F"," degrees",$regs[1]);
        	}

                if(preg_match("/<p><span class=\"myforecast-current-sm\">(.*)<\/span><\/p>/", $html, $regs)) {
                        $temperature_c = str_replace("&deg;C"," degrees celcius",$regs[1]);
                }

                if(preg_match("/<p class=\"myforecast-current\">(.*)<\/p>/", $html, $regs)) {
          		$weather = trim($regs[1]);
                }

                if(preg_match("/<li><span class=\"label\">Wind Chill<\/span>(.*?) \((.*?)\)<\/li>/", $html, $regs)) {
                	$windchill = str_replace("&deg;F"," degrees",$regs[1]);
          		$windchill_c = str_replace("&deg;C"," degrees celcius",$regs[2]);
                }

                //Last Update on
                if(preg_match("/<p class=\"current-conditions-timestamp\">Last Update on (.*?)<\/p>/", $html, $regs)) {
          		$updated = date("h:i A",strtotime(trim($regs[1])));
                }      
        
	unset($this->ini_array[$this->zip]);
        $this->ini_array[$this->zip] = array("location" => $location, "temperature" => $temperature, "temperature_c" => $temperature_c,
                         					 "conditions" => $weather, "windchill" => $windchill, "windchill_c" => $windchill_c,
                         					 "updated" => $updated, "cache" => mktime());
        $helpers = new MagicHelpers;
        $helpers->write_ini_file($this->base."/weather_cache_current",$this->ini_array);
        return $this->ini_array[$this->zip];
      }
      else
        return false;
    }
    else  {
      return $cached_data;
    } 
  }
  function get_location($location)  {
    $state_list = array('AL'=>"Alabama",  
			'AK'=>"Alaska",  
			'AZ'=>"Arizona",  
			'AR'=>"Arkansas",  
			'CA'=>"California",  
			'CO'=>"Colorado",  
			'CT'=>"Connecticut",  
			'DE'=>"Delaware",  
			'DC'=>"District Of Columbia",  
			'FL'=>"Florida",  
			'GA'=>"Georgia",  
			'HI'=>"Hawaii",  
			'ID'=>"Idaho",  
			'IL'=>"Illinois",  
			'IN'=>"Indiana",  
			'IA'=>"Iowa",  
			'KS'=>"Kansas",  
			'KY'=>"Kentucky",  
			'LA'=>"Louisiana",  
			'ME'=>"Maine",  
			'MD'=>"Maryland",  
			'MA'=>"Massachusetts",  
			'MI'=>"Michigan",  
			'MN'=>"Minnesota",  
			'MS'=>"Mississippi",  
			'MO'=>"Missouri",  
			'MT'=>"Montana",
			'NE'=>"Nebraska",
			'NV'=>"Nevada",
			'NH'=>"New Hampshire",
			'NJ'=>"New Jersey",
			'NM'=>"New Mexico",
			'NY'=>"New York",
			'NC'=>"North Carolina",
			'ND'=>"North Dakota",
			'OH'=>"Ohio",  
			'OK'=>"Oklahoma",  
			'OR'=>"Oregon",  
			'PA'=>"Pennsylvania",  
			'RI'=>"Rhode Island",  
			'SC'=>"South Carolina",  
			'SD'=>"South Dakota",
			'TN'=>"Tennessee",  
			'TX'=>"Texas",  
			'UT'=>"Utah",  
			'VT'=>"Vermont",  
			'VA'=>"Virginia",  
			'WA'=>"Washington",  
			'WV'=>"West Virginia",  
			'WI'=>"Wisconsin",  
			'WY'=>"Wyoming");
    //list($city,$state) = split(", ",$location);
    list($city,$state) = explode(" ", $location, 2);
    if($state_list[$state])
      return $city." ".$state_list[$state];
    else
      return $location;
  }
}
?>

