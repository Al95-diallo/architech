<?php

namespace App\Services;

use App\Project;
use Common\Settings\Settings;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Str;

class RenderUserSite
{
    /**
     * @var Project
     */
    private $project;
    /**
     * @var ProjectRepository
     */
    private $repository;
    /**
     * @var Settings
     */
    private $settings;
    /**
     * @var Request
     */
    private $request;

    /**
     * @param Project $project
     * @param ProjectRepository $repository
     * @param Settings $settings
     * @param Request $request
     */
    public function __construct(
        Project $project,
        ProjectRepository $repository,
        Settings $settings, Request $request
    )
    {
        $this->project = $project;
        $this->repository = $repository;
        $this->settings = $settings;
        $this->request = $request;
    }

    /**
     * @param Project $project
     * @param string $pageName
     * @return string
     */
    public function execute($project, $pageName)
    {
        try {
            $html = $this->repository->getPageHtml($project, $pageName);
            $html = preg_replace('/<base.href=".+?">/', '', $html);
            return $this->replaceRelativeLinks($project, $html);
        } catch (FileNotFoundException $e) {
            return abort(404);
        }
    }

    /**
     * Replace relative urls in html to absolute ones.
     *
     * @param Project $project
     * @param string $html
     * @return mixed
     */
    private function replaceRelativeLinks($project, $html)
    {
        $assetBaseUri = url("storage/projects/{$project->users->first()->id}/$project->uuid");

        preg_match_all('/<link.*?href="(.+?)"/i', $html, $linkMatches);
        $html = $this->prefixAssetUrls($html, $linkMatches[1], $assetBaseUri);

        preg_match_all('/<script.*?src="(.+?)"/i', $html, $scriptMatches);
        $html = $this->prefixAssetUrls($html, $scriptMatches[1], $assetBaseUri);

        preg_match_all('/<form.*?action="(.+?)"/i', $html, $formMatches);
        $html = $this->prefixAssetUrls($html, $formMatches[1], $assetBaseUri);

        preg_match_all('/<img.*?src="(.+?)"/i', $html, $imgMatches);
        $html = $this->prefixAssetUrls($html, $imgMatches[1], $assetBaseUri);

        preg_match_all('/style="background-image:.url\((.+?)\)/i', $html, $bgImageMatches);
        $html = $this->prefixAssetUrls($html, $bgImageMatches[1], $assetBaseUri);

        return $html;
    }

    private function prefixAssetUrls(string $html, array $urls, string $baseUri)
    {
        foreach (array_unique($urls) as $url) {
            // asset is already absolute url
            if (Str::startsWith($url, ['//', 'http'])) continue;

            $url = str_replace('"', '', htmlspecialchars_decode($url));
            $html = str_replace($url, "$baseUri/$url", $html);
        }

        return $html;
    }
}
