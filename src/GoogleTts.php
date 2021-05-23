<?php

namespace Lengkeng\GoogleTts;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class GoogleTts
{
    protected $tmpFolder;
    protected $audioFolder;

    function __construct()
    {
        $this->tmpFolder = config('output.tmp', 'audio');
        $this->audioFolder = config('output.audio', 'public/audio');
    }

    public function textToSpeech(string $text)
    {
        $uuid = uniqid('audio', true);
        $sentences = $this->textToSentences($text);
        return $this->textToAudio($sentences, $uuid);
    }

    protected function textToSentences(string $text)
    {
        $text = str_replace("\t", "", $text);
        $text = preg_replace("/\n\s+\n/", "\n\n", $text);
        $text = preg_replace("/[\n]{3,}/", "\n\n", $text);

        $sentences = [];
        $a = explode("\n\n", $text);
        foreach ($a as $b) {
            $b = preg_replace("/http:\/\/(.*?)[\s\)]/", "", $b);
            $b = preg_replace("/http:\/\/([^\s]*?)$/", "", $b);
            $b = preg_replace("/\[\s*[0-9]*\s*\]/", "", $b);
            foreach (array_filter($this->multiExplode(array(
                '. ',
                ', ',
                ';',
                '?',
                '!'
            ),
                $b)) as $sent) { //array_filter to remove all empty row . Notes: space after delimiter char is very important, it will skip broken thousand and decimal number

                if (strlen(trim($sent)) > 3) {
                    $sent = preg_replace("/\n/", " ", $sent);
                    $sent = trim(str_replace("  ", " ", $sent));
                    if (mb_strlen($sent, 'utf8') < 200) {
                        $sentences[] = $sent;
                    } else {
                        $mainpart = $this->truncate($sent, 200, '', false);
                        $sentences[] = $mainpart;
                        $remainpart = trim(str_replace($mainpart, '', $sent));
                        $sentences[] = $remainpart;
                    }
                }
            }
        }
        return $sentences;
    }

    protected function multiExplode($delimiters, $string)
    {
        $ready = str_replace($delimiters, $delimiters[0], $string);
        return explode($delimiters[0], $ready);
    }

    protected function truncate(
        $text,
        $length = 200,
        $ending = '...',
        $exact = true,
        $considerHtml = false
    ) {
        if ($considerHtml) {
            // if the plain text is shorter than the maximum length, return the whole text
            if (strlen(preg_replace('/<.*?>/', '', $text)) <= $length) {
                return $text;
            }

            // splits all html-tags to scanable lines
            preg_match_all('/(<.+?>)?([^<>]*)/s', $text, $lines,
                PREG_SET_ORDER);

            $total_length = strlen($ending);
            $open_tags = array();
            $truncate = '';

            foreach ($lines as $line_matchings) {
                // if there is any html-tag in this line, handle it and add it (uncounted) to the output
                if (!empty($line_matchings[1])) {
                    // if it’s an “empty element” with or without xhtml-conform closing slash (f.e.)
                    if (preg_match('/^<(\s*.+?\/\s*|\s*(img|br|input|hr|area|base|basefont|col|frame|isindex|link|meta|param)(\s.+?)?)>$/is',
                        $line_matchings[1])) {
                        // do nothing
                        // if tag is a closing tag (f.e.)
                    } else {
                        if (preg_match('/^<\s*\/([^\s]+?)\s*>$/s',
                            $line_matchings[1], $tag_matchings)) {
                            // delete tag from $open_tags list
                            $pos = array_search($tag_matchings[1], $open_tags);
                            if ($pos !== false) {
                                unset($open_tags[$pos]);
                            }
                            // if tag is an opening tag (f.e. )
                        } else {
                            if (preg_match('/^<\s*([^\s>!]+).*?>$/s',
                                $line_matchings[1], $tag_matchings)) {
                                // add tag to the beginning of $open_tags list
                                array_unshift($open_tags,
                                    strtolower($tag_matchings[1]));
                            }
                        }
                    }
                    // add html-tag to $truncate’d text
                    $truncate .= $line_matchings[1];
                }

                // calculate the length of the plain text part of the line; handle entities as one character
                $content_length = strlen(preg_replace('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|&#x[0-9a-f]{1,6};/i',
                    ' ', $line_matchings[2]));
                if ($total_length + $content_length > $length) {
                    // the number of characters which are left
                    $left = $length - $total_length;
                    $entities_length = 0;
                    // search for html entities
                    if (preg_match_all('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|&#x[0-9a-f]{1,6};/i',
                        $line_matchings[2], $entities, PREG_OFFSET_CAPTURE)) {
                        // calculate the real length of all entities in the legal range
                        foreach ($entities[0] as $entity) {
                            if ($entity[1] + 1 - $entities_length <= $left) {
                                $left--;
                                $entities_length += strlen($entity[0]);
                            } else {
                                // no more characters left
                                break;
                            }
                        }
                    }
                    $truncate .= substr($line_matchings[2], 0,
                        $left + $entities_length);
                    // maximum lenght is reached, so get off the loop
                    break;
                }

                $truncate .= $line_matchings[2];
                $total_length += $content_length;

                // if the maximum length is reached, get off the loop
                if ($total_length >= $length) {
                    break;
                }
            }
        } else {
            if (strlen($text) <= $length) {
                return $text;
            }

            $truncate = substr($text, 0, $length - strlen($ending));
        }

        // if the words shouldn't be cut in the middle...
        if (!$exact) {
            // ...search the last occurance of a space...
            $spacepos = strrpos($truncate, ' ');
            if (isset($spacepos)) {
                // ...and cut the text in this position
                $truncate = substr($truncate, 0, $spacepos);
            }
        }

        // add the defined ending to the text
        $truncate .= $ending;

        if ($considerHtml) {
            // close all unclosed html-tags
            foreach ($open_tags as $tag) {
                $truncate .= '';
            }
        }

        return $truncate;
    }

    protected function textToAudio($sentences, $uuid)
    {
        $sources = [];
        $id = 0;
        foreach ($sentences as $sentence) {
            $id++;
            $storePath = storage_path("app/{$this->tmpFolder}/$uuid");
            Storage::makeDirectory("{$this->tmpFolder}/$uuid");
            $storePath .= "/{$id}.mp3";
            $this->getGoogleTTS($sentence, $storePath);
            $sources[] = "{$this->tmpFolder}/$uuid/{$id}.mp3";
        }

        FFMpeg::fromDisk('local')
            ->open($sources)
            ->export()
            ->concatWithoutTranscoding()
            ->save("{$this->audioFolder}/{$uuid}.mp3");
        return $uuid;
    }

    protected function getGoogleTTS(string $sentence, string $storePath)
    {
        $lang = config('language', 'vi');
        $url = 'http://translate.google.com/translate_tts?ie=UTF-8&q=' . urlencode($sentence) . '&tl=' . $lang . '&total=1&idx=0&textlen=1000&client=tw-ob'; //&ttsspeed=1';
        $response = $this->curlExec($url);
        file_put_contents($storePath, $response);
    }

    protected function curlExec($url)
    {

        $ch = curl_init();

        // set URL and other appropriate options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/5.0 (Windows NT 6.2; rv:17.0) Gecko/20100101 Firefox/17.0');
        //curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
        //curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_ENCODING, 'UTF-8');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // follow 302 header
        curl_setopt($ch, CURLOPT_FRESH_CONNECT,
            true); //Don't use cache version, "Cache-Control: no-cache"

        $response = curl_exec($ch);
        curl_close($ch);

        //return (string) $body;
        return $response;
    }

    protected function initFolder()
    {
        $path = storage_path("app/{$this->tmpFolder}");
        if (!File::exists($path)) {
            Storage::makeDirectory($this->tmpFolder);
        }
    }
}
