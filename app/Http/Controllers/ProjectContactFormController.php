<?php

namespace App\Http\Controllers;

use App\Notifications\UserSiteFormSubmitted;
use App\Project;
use Common\Core\BaseController;
use Illuminate\Http\Request;

class ProjectContactFormController extends BaseController
{
    /**
     * @var Request
     */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function sendMessage(Project $project)
    {
        $this->authorize('update', $project);

        $project->users->first->notify(new UserSiteFormSubmitted($this->request->all(), $project));

        return $this->success();
    }
}
