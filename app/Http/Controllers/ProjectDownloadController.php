<?php

namespace App\Http\Controllers;

use App\Services\ProjectRepository;
use Carbon\Carbon;
use Common\Core\BaseController;
use Storage;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

class ProjectDownloadController extends BaseController
{
    /**
     * @var ProjectRepository
     */
    private $projectRepository;

    /**
     * @param ProjectRepository $projectRepository
     */
    public function __construct(ProjectRepository $projectRepository)
    {
        $this->projectRepository = $projectRepository;
    }

    /**
     * @param int $id
     * @return void
     */
    public function download($id)
    {
        $project = $this->projectRepository->findOrFail($id);
        $projectPath = $this->projectRepository->getProjectPath($project);
        $disk = Storage::disk('projects');

        $this->authorize('download', $project);

        $options = new Archive();
        $options->setSendHttpHeaders(true);

        $timestamp = Carbon::now()->getTimestamp();
        $zip = new ZipStream("download-$timestamp.zip", $options);

        $paths = $disk->allFiles($projectPath);
        foreach ($paths as $relativePath) {
            $zip->addFileFromPath(str_replace($projectPath, '', $relativePath), $disk->path($relativePath));
        }

        $zip->finish();
    }
}
