#!/usr/bin/env php
<?php
require_once('getID3/getid3/getid3.php');
require_once('getID3/getid3/write.php');

class Joox
{
    const COLLECTING_JSON = 'Collecting JSON';
    public $apiURL = 'https://api.joox.com/web-fcgi-bin/web_get_songinfo?songid={single}&contry=id&lang=id&https_only=1';

    private $_urls;
    private $_links = [];
    private $thread;
    private $totalMusik;
    private $totalDownloaded = 0;

    public function __construct($urls, $thread = 10)
    {
        $this->_urls = $urls;
        $this->thread = $thread;
    }

    public function run()
    {
        $this->getLinks($this->_urls);
        $this->processLinks();
    }

    /**
     * @param $msg
     */
    private function log($msg)
    {
        echo $msg . "\n";
    }

    /**
     * @param string|array $urls
     * @return void
     */
    public function getLinks($urls)
    {
        if (is_array($urls)) {
            foreach ($urls as $url) {
                $this->getLinks($url);
            }
        } elseif (is_string($urls)) {
            $this->log('GET URL: ' . $urls);
            $ch = curl_init();
            curl_setopt_array($ch, array(
                    CURLOPT_URL => $urls,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_SSL_VERIFYPEER => FALSE,
                    CURLOPT_SSL_VERIFYHOST => FALSE,
                )
            );
            $html = curl_exec($ch);
            curl_close($ch);
            $dom = new DOMDocument;
            @$dom->loadHTML($html);
            foreach ($dom->getElementsByTagName('a') as $node) {
                $href = $node->getAttribute("href");
                if (strpos($href, '/id/single/') !== false) {
                    $idMusic = str_replace('/id/single/', '', $href);
                    $this->_links[] = strtr($this->apiURL, [
                        '{single}' => $idMusic
                    ]);
                }
            }
        }
    }

    protected function progress_bar($done, $total, $info = "", $done_count = 0, $total_count = 0, $width = 50)
    {
        $perc = round(($done * 100) / $total);
        $bar = round(($width * $perc) / 100);
        $count = '';
        if ($done_count && $total_count)
            $count = "$done_count/$total_count";
        echo sprintf("%s%%[%s>%s]%s\r", $perc, str_repeat("=", $bar), str_repeat(" ", $width - $bar), $info . ' ' . $count);
    }

    protected function curlRequests($url_array, $thread_width = 10, $cache = false, $info = '')
    {
        $threads = 0;
        $master = curl_multi_init();
        $curl_opts = array(CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_SSL_VERIFYPEER => FALSE,
            CURLOPT_SSL_VERIFYHOST => FALSE,
        );
        $results = $results_cache = array();

        $count = 0;
        foreach ($url_array as $url) {
            $params = [];
            if (is_array($url)) {
                $params = $url;
                $url = $url['url'];
            }
            $count++;
            if ($cache && file_exists('data/cache/' . md5($url))) {
                $results_cache[$count] = array_merge($params, [
                    'result' => file_get_contents('data/cache/' . md5($url)),
                    'url' => $url
                ]);
            } else {
                $curl_opts[CURLOPT_PROGRESSFUNCTION] = function ($resource, $download_size, $downloaded_size, $upload_size, $uploaded_size) use ($count, $url_array, $info) {
                    if ($download_size != 0) {
                        if ($info == self::COLLECTING_JSON)
                            $this->progress_bar($downloaded_size, $download_size, $info, $count, count($url_array));
                        else
                            $this->progress_bar($downloaded_size, $download_size, $info, $this->totalDownloaded, $this->totalMusik);
                    }

                };
                $ch = curl_init();
                $curl_opts[CURLOPT_URL] = $url;

                curl_setopt_array($ch, $curl_opts);
                curl_multi_add_handle($master, $ch); //push URL for single rec send into curl stack
                $results[$count] = array_merge($params, array("url" => $url, "handle" => $ch));
                $threads++;
                if ($threads >= $thread_width) { //start running when stack is full to width
                    while ($threads >= $thread_width) {
                        usleep(100);
                        while (($execrun = curl_multi_exec($master, $running)) === -1) {
                        }
                        curl_multi_select($master);
                        // a request was just completed - find out which one and remove it from stack
                        while ($done = curl_multi_info_read($master)) {
                            foreach ($results as &$res) {
                                if ($res['handle'] == $done['handle']) {
                                    $res['result'] = curl_multi_getcontent($done['handle']);
                                }
                            }
                            curl_multi_remove_handle($master, $done['handle']);
                            curl_close($done['handle']);
                            $threads--;
                        }
                    }
                }
            }

        }
        do { //finish sending remaining queue items when all have been added to curl
            usleep(100);
            while (($execrun = curl_multi_exec($master, $running)) === -1) {
            }
            curl_multi_select($master);
            while ($done = curl_multi_info_read($master)) {
                foreach ($results as $i => &$res) {
                    if ($res['handle'] == $done['handle']) {
                        $res['result'] = curl_multi_getcontent($done['handle']);
                        if ($cache) {
                            file_put_contents('data/cache/' . md5($res['url']), $res['result']);
                        }
                    }
                }
                curl_multi_remove_handle($master, $done['handle']);
                curl_close($done['handle']);
                $threads--;
            }
        } while ($running > 0);
        curl_multi_close($master);
        return array_merge($results, $results_cache);
    }

    protected function processLinks()
    {
        $jsons = $this->curlRequests($this->_links, $this->thread, false, self::COLLECTING_JSON);
        $this->totalMusik = count($jsons);
        foreach ($jsons as $json) {
            $this->totalDownloaded++;
            $api_lyrics = 'https://api-jooxtt.sanook.com/web-fcgi-bin/web_lyric?country=id&lang=id&musicid={single}';
            $data = trim(trim($json['result'], ')'), 'MusicInfoCallback(');
            try {
                $data = @json_decode($data);
                if (!is_object($data)) continue;
                $fileJson = 'data/json/' . $data->msong . '.json';
                $fileLyrics = 'data/lyrics/' . $data->msong . '.lyrics';
                $fileArtwork = 'data/artwork/' . $data->msong . '.png';
                $fileMp3 = 'data/mp3/' . $data->msong . '.mp3';
                if (file_exists($fileJson)) {
                    $this->log("EXISTS" . $fileJson);
                    continue;
                }
                $lyrics_api = strtr($api_lyrics, [
                    '{single}' => $data->encodeSongId
                ]);
                @file_put_contents($fileJson, json_encode($data, JSON_PRETTY_PRINT));
                $urlMusic = [
                    ['url' => $lyrics_api, 'name' => 'lyrics']
                ];
                if (isset($data->imgSrc) && $data->imgSrc) {
                    $urlMusic[] = ['url' => $data->imgSrc, 'name' => 'artwork'];
                } elseif (isset($data->album_url) && $data->album_url) {
                    $urlMusic[] = ['url' => $data->album_url, 'name' => 'artwork'];
                }
                if (isset($data->mp3Url) && $data->mp3Url) {
                    $urlMusic[] = ['url' => $data->mp3Url, 'name' => 'mp3'];
                    $musicData = $this->curlRequests($urlMusic, $this->thread, false, 'Downloading: ' . $data->msong);
                    foreach ($musicData as $i => $md) {
                        if (isset($md['name'])) {
                            $result = $md['result'];
                            $fileName = '';
                            switch ($md['name']) {
                                case "artwork":
                                    $fileName = $fileArtwork;
                                    break;
                                case "lyrics":
                                    $fileName = $fileLyrics;
                                    break;
                                case "mp3":
                                    $fileName = $fileMp3;
                                    break;
                            }
                            if ($fileName) {
                                file_put_contents($fileName, $result);
                            }
                        }
                    }
                    $this->genTag($fileMp3, $fileLyrics, $fileArtwork, $data);
                }
            } catch (Exception $e) {
                print_r($e);
            }
        }
    }

    protected function updateMp3($url)
    {

    }

    /**
     * @param string $fileMp3
     * @param string $fileLyrics
     * @param string $fileArtwork
     * @param object $data
     * @throws getid3_exception
     */
    protected function genTag($fileMp3, $fileLyrics, $fileArtwork, $data)
    {
        if (!file_exists($fileMp3))
            return;
        $TextEncoding = 'UTF-8';
        $getID3 = new getID3;
        $ThisFileInfo = $getID3->analyze($fileMp3);
        $genre = '';
        if (isset($ThisFileInfo['id3v1']) && isset($ThisFileInfo['id3v1']['genre']))
            $genre = $ThisFileInfo['id3v1']['genre'] . "\n";
        if (isset($ThisFileInfo['id3v2']) && isset($ThisFileInfo['id3v2']['genre']))
            $genre = $ThisFileInfo['id3v2']['genre'] . "\n";

        $getID3->setOption(array('encoding' => $TextEncoding));
        $tagwriter = new getid3_writetags;
        $tagwriter->filename = $fileMp3;
        $tagwriter->tagformats = array('id3v1', 'id3v2.3');
        $tagwriter->overwrite_tags = true;
        $tagwriter->tag_encoding = $TextEncoding;
//    $tagwriter->remove_other_tags = true;
        $TagData = array(
            'title' => array($data->msong),
            'artist' => array($data->msinger),
            'album' => array($data->malbum),
            'year' => array(date('Y', strtotime($data->public_time))),
            'comment' => array('upik saleh => joox'),
            'composer' => array('www.codeup.id'),
//        'track'                  => array('04/16'),
//        'popularimeter'          => array('email'=>'user@example.net', 'rating'=>128, 'data'=>0),
//        'unique_file_identifier' => array('ownerid'=>'user@example.net', 'data'=>md5(time())),
        );
        if ($genre)
            $TagData['genre'] = array($genre);
        $artwork = $fileArtwork;
        if (file_exists($artwork)) {
            $exif_imagetype = exif_imagetype($artwork);
            $TagData['attached_picture'][0]['data'] = file_get_contents($artwork);
            $TagData['attached_picture'][0]['picturetypeid'] = 0;
            $TagData['attached_picture'][0]['description'] = 'Upik Saleh => Artwork';
            $TagData['attached_picture'][0]['mime'] = image_type_to_mime_type($exif_imagetype);

        }
        $lirycs = json_decode(file_get_contents($fileLyrics));
        if ($lirycs && isset($lirycs->lyric)) {
            $TagData['unsynchronised_lyric'] = array(base64_decode($lirycs->lyric));
        }
        $tagwriter->tag_data = $TagData;
        if ($tagwriter->WriteTags()) {
            if (!empty($tagwriter->warnings)) {
                echo "There were some warnings:\n" . implode("\n", $tagwriter->warnings);
            }
        } else {
            echo "Failed to write tags!\n" . implode("\n", $tagwriter->errors) . "\n";
        }

    }

    function formatBytes($bytes, $precision = 2)
    {
        $units = array("b", "kb", "mb", "gb", "tb");
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . " " . $units[$pow];
    }
}

if (!isset($argv[1])) {
    echo "Use ./Joox.php {url} {thread}\n";
    exit;
}
if (!isset($argv[2])) {
    $argv[2] = 10;
}
$joox = new Joox($argv[1], $argv[2]);
$joox->run();