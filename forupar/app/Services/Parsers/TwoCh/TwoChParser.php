<?php

namespace App\Services\Parsers\TwoCh;

use App\Services\Parsers\Downloader;
use App\Services\Parsers\ParserService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\Exception;

class TwoChParser extends ParserService
{
    const PAGE_LIST = '[board]/[page].json';
    const PAGE_THREAD = '[board]/res/[id].json';

    protected string $current_thread;

    public function loadNew()
    {
        $data = $this->openList();
        $threads = $this->getThreads($data);

        foreach ($threads as $thread_id) {
            if (empty($thread_id)) {
                throw new Exception('Not found threads');
            }
            $this->current_thread = $thread_id;
            $data = $this->openThread($thread_id);
            $data = $this->parseThread($data);
        }
    }

    private function generateListUri()
    {
        return $this->parser->site . str_replace(['[board]', '[page]'], ['b', 'index'], self::PAGE_LIST);
    }

    private function generateThreadUri()
    {
        return $this->parser->site . str_replace(['[board]', '[id]'], ['b',  $this->current_thread], self::PAGE_THREAD);
    }

    private function openList()
    {
        $json = $this->downloader->get($this->generateListUri());
        return json_decode($json, true);
    }

    private function getThreads($data)
    {
        $threads = $data['threads'] ?? [];

        $result_threads = [];

        foreach ($threads as $thread) {
            if (empty($thread['posts']) || empty($thread['thread_num'])) {
                continue;
            }

            $thread_id = $thread['thread_num'];
            $head_thread = array_shift($thread['posts']);

            if (empty($head_thread['comment'])) {
                continue;
            }

            $search = [
                'американцы'
            ];

            if (empty(Str::contains(Str::lower($head_thread['comment']), $search))) {
                continue;
            }

            $result_threads[] = $thread_id;
        }

        return $result_threads;
    }

    private function openThread()
    {
        $json = $this->downloader->get($this->generateThreadUri());
        return json_decode($json, true);
    }

    private function parseThread($data)
    {
        $thread_title = $data['title'] ?? '';
        $main_img = $data['thread_first_image'] ?? '';
        $result = $this->loadImg($main_img);
        $threads = $data['threads'] ?? [];
        $thread = array_shift($threads);
        $posts = $thread['posts'] ?? [];

        if (empty($posts)) {
            return [];
        }

        $result_post = [];

        foreach ($posts as $post) {
            if (empty($post['num'])) {
                continue;
            }

            if (empty($post['comment']) && empty($post['files'])) {
                continue;
            }

            $result_post = [
                'id' => $post['num'],
                'parent' => $post['parent'] ?? '',
                'comment' => $post['comment'],
                'timestamp' => $post['timestamp'] ?? ''
            ];

            $files = $post['files'];

            foreach ($files as $file) {
                if (empty($file['path'])) {
                    continue;
                }
                $this->loadImg($file['path']);
            }
        }

        return $result_post;
    }

    private function loadImg($urn): bool
    {
        $img_url = !empty($urn) ? $this->parser->site.$urn : '';

        if (empty($img_url)) {
            return false;
        }
        $filename = $this->getLatestPartFromUrl($urn);
        $filename_img = "TwoCh/thread/{$this->current_thread}/img/$filename";
        if (Storage::exists($filename_img)) {
            //check doubles
            return true;
        }

        $file = file_get_contents($img_url);
        Storage::put($filename_img, $file);

        if (Storage::exists($filename_img)) {
            return true;
        }

        return false;
    }

    private function getLatestPartFromUrl(string $url): string
    {
        $split_url = explode('/', $url);
        $split_url = array_filter($split_url, function($element) {
            return !empty($element);
        });

        return end($split_url);
    }



}
