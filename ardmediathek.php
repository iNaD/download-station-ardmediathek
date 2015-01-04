<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.1
 * @copyright 2015 Daniel Gehn
 * @license http://opensource.org/licenses/MIT Licensed under MIT License
 */

class SynoFileHostingARDMediathek {
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;

    private $LogPath = '/tmp/ard-mediathek.log';
    private $LogEnabled = false;

    public function __construct($Url, $Username = '', $Password = '', $HostInfo = '') {
        $this->Url = $Url;
        $this->Username = $Username;
        $this->Password = $Password;
        $this->HostInfo = $HostInfo;

        $this->DebugLog("URL: $Url");
    }

    //This function returns download url.
    public function GetDownloadInfo() {
        $ret = FALSE;

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
        $hits = array();

        $this->DebugLog("Getting download url for $this->Url");

        if(preg_match('#documentId=([0-9]+)#i', $this->Url, $match) === 1)
        {
            $id = $match[1];

            $this->DebugLog("ID is $id");

            $this->DebugLog("Getting JSON data from http://www.ardmediathek.de/play/media/$id");

            $curl = curl_init();

            curl_setopt($curl, CURLOPT_URL, 'http://www.ardmediathek.de/play/media/' . $id);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);

            $data = json_decode(curl_exec($curl));

            curl_close($curl);

            $bestFormat = array(
                'quality'   => -1,
                'url'       => '',
            );

            foreach($data->_mediaArray as $mediaPlugin)
            {
                foreach($mediaPlugin->_mediaStreamArray as $mediaStream)
                {
                    if($mediaStream->_cdn == "default")
                    {
                        if(strpos($mediaStream->_stream, '.mp4') !== false && $mediaStream->_quality > $bestFormat['quality'])
                        {
                            $bestFormat = array(
                                'quality'   => $mediaStream->_quality,
                                'url'       => $mediaStream->_stream,
                            );
                        }
                    }
                }
            }

            $this->DebugLog('Best format is ' . json_encode($bestFormat));

            $DownloadInfo = array();
            $DownloadInfo[DOWNLOAD_URL] = trim($bestFormat['url']);

            return $DownloadInfo;
        }

        $this->DebugLog("Couldn't identify id");

        return FALSE;
    }

    private function DebugLog($message)
    {
        if($this->LogEnabled === true)
        {
            file_put_contents($this->LogPath, $message . "\n", FILE_APPEND);
        }
    }
}
?>
