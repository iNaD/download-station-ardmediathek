<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.6a
 * @copyright 2015 Daniel Gehn
 * @license http://opensource.org/licenses/MIT Licensed under MIT License
 */

class SynoFileHostingARDMediathek {
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;

    private $LogPath = '/tmp/ard-mediathek.log';
    private $LogEnabled = true;

    public function __construct($Url, $Username = '', $Password = '', $HostInfo = '') {
        $this->Url = $Url;
        $this->Username = $Username;
        $this->Password = $Password;
        $this->HostInfo = $HostInfo;

        $this->DebugLog("URL: $Url");
    }

    //This function returns download url.
    public function GetDownloadInfo() {
        $this->DebugLog("GetDownloadInfo called");

        $ret = $this->Download();

        return $ret;
    }

    public function onDownloaded()
    {
    }

    public function Verify($ClearCookie = '')
    {
        $this->DebugLog("Verifying User");

        return USER_IS_PREMIUM;
    }

    //This function gets the download url
    private function Download() {
        $this->DebugLog("Getting download url for $this->Url");

        /**
         * ARD Mediathek
         */
        if((strpos($this->Url, 'ardmediathek.de') !== false || strpos($this->Url, 'mediathek.daserste.de') !== false) && preg_match('#documentId=([0-9]+)#i', $this->Url, $match) === 1)
        {
            return $this->ard($match[1], $this->getTitle($this->Url));
        }

        /**
         * RBB Mediathek
         */
        if(strpos($this->Url, 'mediathek.rbb-online.de') !== false && preg_match('#documentId=([0-9]+)#i', $this->Url, $match) === 1)
        {
            return $this->ard($match[1], $this->getTitle($this->Url), 'http://mediathek.rbb-online.de/play/media/');
        }

        /**
         * Einsfestival implementation
         */
        if(strpos($this->Url, 'einsfestival.de/mediathek') !== false)
        {
            return $this->einsfestival();
        }

        $this->DebugLog("Couldn't identify id");

        return FALSE;
    }

    private function getTitle($url) {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $rawXML = curl_exec($curl);

        $episodeTitle = '';

        $match = array();

        if(preg_match('#<meta name="dcterms.title" content="(.*?)"\/>#i', $rawXML, $match) == 1)
        {
            $episodeTitle = $match[1];
        }

        return $episodeTitle;
    }

    private function ard($id, $title = '', $baseUrl = 'http://www.ardmediathek.de/play/media/')
    {
        $this->DebugLog("ID is $id");

        $this->DebugLog("Getting JSON data from " . $baseUrl . $id);

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $baseUrl . $id);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $data = json_decode(curl_exec($curl));

        curl_close($curl);

        return $this->getBestStream($data, $title);
    }

    private function getBestStream($data, $title = '')
    {
        $bestStream = array(
            'quality'   => -1,
            'url'       => '',
        );

        foreach($data->_mediaArray as $mediaPlugin)
        {
            foreach($mediaPlugin->_mediaStreamArray as $mediaStream)
            {
                if(property_exists($mediaStream, '_stream'))
                {
                    if(property_exists($mediaStream, '_cdn'))
                    {
                        if($mediaStream->_cdn == "default" || ($mediaStream->_cdn == "akamai" && $this->startsWith($mediaStream->_stream, 'http')))
                        {
                            $bestStream = $this->testStreamQuality($this->parseStream($mediaStream->_stream, $mediaStream->_quality), $bestStream);
                        }
                    }
                    else
                    {
                        $bestStream = $this->testStreamQuality($this->parseStream($mediaStream->_stream, $mediaStream->_quality), $bestStream);
                    }
                }
            }
        }

        $this->DebugLog('Best format is ' . json_encode($bestStream));

        $url = trim($bestStream['url']);

        $filename = '';
        $pathinfo = pathinfo($url);

        if(empty($title))
        {
            $filename = $pathinfo['basename'];
        }
        else
        {
            $filename .=  $title . '.' . $pathinfo['extension'];
        }

        $this->DebugLog('Filename based on title "' . $title . '" is: "' . $filename . '"');

        $DownloadInfo = array();
        $DownloadInfo[DOWNLOAD_URL] = $url;
        $DownloadInfo[DOWNLOAD_FILENAME] = $filename;

        return $DownloadInfo;
    }

    private function einsfestival()
    {
        $this->DebugLog("Catching einsfestival mediathek content");

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $this->Url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $rawXML = curl_exec($curl);

        if(!$rawXML)
        {
            $this->DebugLog("Failed to retrieve XML. Error Info: " . curl_error($curl));
            return false;
        }

        curl_close($curl);

        preg_match_all("#jQuery\(video\).attr\('src','(.*?)'\);#is", $rawXML, $matches);

        $url = '';

        foreach($matches[1] as $match)
        {
            if(preg_match('#http://(.*?).mp4#i', $match) > 0)
            {
                $url = $match;
            }
        }

        $this->DebugLog('Best format is ' . $url);

        $url = trim($url);

        $episodeTitle = '';
        $filename = '';
        $pathinfo = pathinfo($url);

        $match = array();

        if(preg_match('#class="videoInfoHead">(.*?)<\/#i', $rawXML, $match) == 1)
        {
            $episodeTitle = $match[1];
            $filename .= $episodeTitle;
        }
        else
        {
            $filename .= $pathinfo['basename'];
        }


        if(empty($filename))
        {
            $filename = $pathinfo['basename'];
        }
        else
        {
            $filename .= '.' . $pathinfo['extension'];
        }

        $this->DebugLog('Filename based on episodeTitle "' . $episodeTitle . '" is: "' . $filename . '"');

        $DownloadInfo = array();
        $DownloadInfo[DOWNLOAD_URL] = $url;
        $DownloadInfo[DOWNLOAD_FILENAME] = $filename;

        return $DownloadInfo;
    }

    private function testStreamQuality($streams, $bestStream)
    {
        if(is_array($streams) && !isset($streams['url']))
        {
            foreach($streams as $stream)
            {
                $bestStream = $this->testStreamQuality($stream, $bestStream);
            }

            return $bestStream;
        }

        if(strpos($streams['url'], '.mp4') !== false && $streams['quality'] > $bestStream['quality'])
        {
            return $streams;
        }

        return $bestStream;
    }

    private function parseStream($stream, $baseQuality = -1)
    {
        if(is_array($stream))
        {
            $streams = array();
            foreach($stream as $streamFile)
            {
                $streams[] = $this->parseStream($streamFile, $baseQuality);
            }

            return $streams;
        }

        $streamArray = array(
            'quality' => $baseQuality,
            'url' => $stream
        );

        if(strpos($stream, '_X.mp4') !== false)
        {
            $streamArray['quality'] += 1;
        }

        return $streamArray;
    }

    private function DebugLog($message)
    {
        if($this->LogEnabled === true)
        {
            file_put_contents($this->LogPath, $message . "\n", FILE_APPEND);
        }
    }

    private function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
    }
}
?>
