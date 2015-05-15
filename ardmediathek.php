<?php

/**
 * @author Daniel Gehn <me@theinad.com>
 * @version 0.6b
 * @copyright 2015 Daniel Gehn
 * @license http://opensource.org/licenses/MIT Licensed under MIT License
 */

require_once 'provider.php';

class SynoFileHostingARDMediathek extends TheiNaDProvider {

    protected $LogPath = '/tmp/ard-mediathek.log';

    public function GetDownloadInfo() {
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

    protected function getTitle($url) {
        $rawXML = $this->curlRequest($url);
        if($rawXML === null)
        {
            return '';
        }

        $episodeTitle = '';

        $match = array();

        if(preg_match('#<meta name="dcterms.title" content="(.*?)"\/>#i', $rawXML, $match) == 1)
        {
            $episodeTitle = $match[1];
        }

        return $episodeTitle;
    }

    protected function ard($id, $title = '', $baseUrl = 'http://www.ardmediathek.de/play/media/')
    {
        $this->DebugLog("ID is $id");

        $this->DebugLog("Getting JSON data from " . $baseUrl . $id);

        $data = json_decode($this->curlRequest($baseUrl . $id));

        $this->DebugLog(json_encode($data));

        if($data === null) {
            return false;
        }

        return $this->getBestStream($data, $title);
    }

    protected function getBestStream($data, $title = '')
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
        $DownloadInfo[DOWNLOAD_FILENAME] = $this->safeFilename($filename);

        return $DownloadInfo;
    }

    protected function einsfestival()
    {
        $this->DebugLog("Catching einsfestival mediathek content");

        $rawXML = $this->curlRequest($this->Url);

        if($rawXML === null)
        {
            return false;
        }

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
            $episodeTitle = mb_convert_encoding($match[1], "UTF-8", "ISO-8859-1");
            $filename .= $episodeTitle . '.' . $pathinfo['extension'];
        }
        else
        {
            $filename .= $pathinfo['basename'];
        }

        $this->DebugLog('Filename based on episodeTitle "' . $episodeTitle . '" is: "' . $filename . '"');

        $DownloadInfo = array();
        $DownloadInfo[DOWNLOAD_URL] = $url;
        $DownloadInfo[DOWNLOAD_FILENAME] = $this->safeFilename($filename);

        return $DownloadInfo;
    }

    protected function testStreamQuality($streams, $bestStream)
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

    protected function parseStream($stream, $baseQuality = -1)
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

}
?>
