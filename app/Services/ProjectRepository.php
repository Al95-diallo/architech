<?php namespace App\Services;

use App\Project;
use Arr;
use Auth;
use Common\Domains\CustomDomain;
use DB;
use File;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Storage;
use Str;

class ProjectRepository
{
    /**
     * @var Project
     */
    private $project;

    /**
     * @var FilesystemAdapter
     */
    private $storage;

    /**
     * @var TemplateLoader
     */
    private $templateLoader;

    /**
     * @param TemplateLoader $templateLoader
     * @param Project $project
     */
    public function __construct(TemplateLoader $templateLoader, Project $project)
    {
        $this->project = $project;
        $this->storage = Storage::disk('projects');
        $this->templateLoader = $templateLoader;
    }

    /**
     * Find project by specified id.
     *
     * @param int $id
     * @return Project
     */
    public function findOrFail($id)
    {
        return $this->project->findOrFail($id);
    }

    public function load(Project $project)
    {
        $path = $this->getProjectPath($project);

        $pages = $this->loadProjectPages($path);

        $loaded = [
            'model' => $project->toArray(),
            'pages' => $pages,
        ];

        //load custom css
        if ($this->storage->exists("$path/css/code_editor_styles.css")) {
            $loaded['css'] = $this->storage->get("$path/css/code_editor_styles.css");
        }

        //load custom js
        if ($this->storage->exists("$path/js/code_editor_scripts.js")) {
            $loaded['js'] = $this->storage->get("$path/js/code_editor_scripts.js");
        }

        //load template
        if ($project->template) {
            $loaded['template'] = $this->templateLoader->load($project->template);
        }

        return $loaded;
    }

    public function getProjectPath(Project $project, bool $absolute = false, int $userId = null): string
    {
        // get user id from pivot in case user was deleted
        $userId = $userId ?? DB::table('users_projects')->where('project_id', $project->id)->first()->user_id;
        $path = "{$userId}/{$project->uuid}";
        return $absolute ? public_path("storage/projects/$path") : $path;
    }

    /**
     * Get html of specified project's page.
     *
     * @param Project $project
     * @param string$name
     * @return string
     */
    public function getPageHtml(Project $project, $name)
    {
        if ( ! $name) $name = 'index';

        $projectPath = $this->getProjectPath($project);

        $name = Str::contains($name, '.html') ? $name : "$name.html";
        $pagePath = "$projectPath/$name";

        //if "index.html" does not exist, load first available page
        if ( ! $this->storage->exists($pagePath)) {
            $name = $this->loadProjectPages($projectPath)->first()['name'];
            $pagePath = "$projectPath/$name.html";
        }

        return $this->storage->get($pagePath);
    }

    public function update(Project $project, $data, $overrideFiles = true)
    {
        // change owner of project
        if (Arr::get($data, 'users') && $project->users->first()->id !== $data['users'][0]['id']) {
            $oldPath = $this->getProjectPath($project, true, $project->users->first()->id);
            $newPath = $this->getProjectPath($project, true, $data['users'][0]['id']);
            File::ensureDirectoryExists($newPath);
            if (File::moveDirectory($oldPath, $newPath, true)) {
                app(CustomDomain::class)->where([
                    'resource_type' => Project::class,
                    'resource_id' => $project->id,
                    'global' => false,
                ])->update(['resource_id' => null, 'resource_type' => null]);
                $project->users()->sync($data['users'][0]['id']);
            }
        }

        $projectPath = $this->getProjectPath($project);

        if (Arr::get($data, 'slug') && $project->slug !== $data['slug']) {
            $project->fill(['slug' => $data['slug']])->save();
        }

        if (isset($data['pages'])) {
            $this->updatePages($project, $data['pages']);
        }

        if ((Arr::get($data, 'template') ?: $project->template) !== $project->template) {
            $this->updateTemplate($project, $data['template'], $overrideFiles);
        }

        if ((Arr::get($data, 'framework') ?: $project->framework) !== $project->framework) {
            $this->addBootstrapFiles($projectPath, $data['framework']);
        }

        if (Arr::get($data, 'theme', $project->theme) !== $project->theme) {
            $this->applyTheme($projectPath, $data['theme']);
        }

        if (Arr::get($data, 'custom_element_css')) {
            $this->addCustomElementCss($projectPath, $data['custom_element_css']);
        }

        // custom css
        if (array_key_exists('css', $data)) {
            $this->storage->put("$projectPath/css/code_editor_styles.css", $data['css']);
        }

        // custom js
        if (array_key_exists('js', $data)) {
            $this->storage->put("$projectPath/js/code_editor_scripts.js", $data['js']);
        }

        $project->fill([
            'name' => Arr::get($data, 'name', $project->name),
            'theme' => Arr::get($data, 'theme', $project->theme),
            'template' => Arr::get($data, 'template', $project->template),
            'published' => Arr::get($data, 'published', $project->published),
        ])->save();
    }

    /**
     * Create a new project.
     *
     * @param array $data
     * @return Project
     */
    public function create($data)
    {
        $project = $this->project->create([
            'name' => $data['name'],
            'slug' => Arr::get($data, 'slug', slugify($data['name'])),
            'template' => Arr::get($data, 'template.name'),
            'uuid' => $data['uuid'] ?? Str::random(36),
            'published' => $data['published'] ?? false,
            'updated_at' => $data['updated_at'] ?? now(),
        ])->fresh();
        $project->users()->attach($data['userId'] ?? Auth::user()->id);

        $projectPath = $this->getProjectPath($project);

        $this->addBootstrapFiles($projectPath, $project->framework);

        //thumbnail
        $this->storage->put("$projectPath/thumbnail.png", Storage::disk('builder')->get(TemplateLoader::DEFAULT_THUMBNAIL));

        //custom css
        $this->storage->put("$projectPath/css/code_editor_styles.css", '');

        //custom js
        $this->storage->put("$projectPath/js/code_editor_scripts.js", '');

        //custom elements css
        $this->addCustomElementCss($projectPath, '');

        //empty theme
        $this->applyTheme($projectPath, null);

        //apply template
        if ($data['template']) {
            $this->applyTemplate($data['template'], $projectPath);
        }

        //create pages
        if (isset($data['pages'])) {
            $this->updatePages($project, $data['pages']);
        }

        return $project;
    }

    /**
     * Delete specified project.
     *
     * @param Project $project
     * @return bool|null
     */
    public function delete(Project $project)
    {
        $path = $this->getProjectPath($project);
        $this->storage->deleteDirectory($path);
        $project->users()->detach();
        return $project->delete();
    }

    /**
     * Update project pages.
     *
     * @param Project $project
     * @param array $pages
     */
    public function updatePages(Project $project, $pages)
    {
        if (empty($pages)) return;

        $projectPath = $this->getProjectPath($project);

        // delete old pages
        collect($this->storage->files($projectPath))->filter(function($path) {
            return Str::contains($path, '.html');
        })->each(function($path) {
            $this->storage->delete($path);
        });

        // store new pages
        collect($pages)->each(function($page) use ($projectPath) {
            $name = slugify($page['name']);
            $this->storage->put("$projectPath/{$name}.html", $page['html']);
        });
    }

    private function addBootstrapFiles($projectPath)
    {
        // font awesome
        File::copyDirectory(
            public_path('builder/font-awesome'),
            public_path("storage/projects/$projectPath/font-awesome")
        );

        // bootstrap
        File::copyDirectory(
            public_path('builder/bootstrap'),
            public_path("storage/projects/$projectPath/bootstrap")
        );
    }

    /**
     * Apply specified theme to the project.
     *
     * @param string $projectPath
     * @param string $themeName
     */
    private function applyTheme($projectPath, $themeName = null)
    {
        $contents = is_null($themeName) ? '' :  $this->getBuilderAsset("themes/$themeName/stylesheet.css");
        $this->storage->put("$projectPath/css/theme.css", $contents);
    }

    /**
     * Update project template to specified one.
     *
     * @param Project $project
     * @param string $templateName
     * @param bool $overrideFiles
     */
    private function updateTemplate(Project $project, $templateName, $overrideFiles = true)
    {
        $oldTemplatePath = "template/$templateName";
        $projectPath = $this->getProjectPath($project);
        $template = $this->templateLoader->load($templateName);
        $builderDisk = Storage::disk('builder');

        //delete old images
        if ($builderDisk->exists("$oldTemplatePath/images")) {
            $paths = $builderDisk->files("$oldTemplatePath/images");

            collect($paths)->each(function ($imagePath) use($projectPath) {
                $imgFileName = basename($imagePath);
                $path = "$projectPath/images/$imgFileName";

                if ( ! $this->storage->exists($path)) {
                    return;
                }

                if ( ! Str::contains($imgFileName, '.')) {
                    $this->storage->deleteDirectory($path);
                } else {
                    $this->storage->delete($path);
                }
            });
        }

        // apply new template
        $this->applyTemplate($template, $projectPath, $overrideFiles);
    }

    public function applyTemplate($templateData, $projectPath, $overrideFiles = true)
    {
        $templateName = strtolower(Str::kebab($templateData['name']));

        // copy template files recursively
        foreach (Storage::disk('builder')->allFiles("templates/$templateName") as $templateFilePath) {
            $innerPath = str_replace('templates'.DIRECTORY_SEPARATOR.$templateName, $projectPath, $templateFilePath);

            // don't override project styles file
            if (Str::contains($innerPath, 'code_editor_styles.css')) continue;

            // don't copy over template config file
            if (Str::contains($innerPath, 'config.json')) continue;

            if ($this->storage->exists($innerPath) && ! $overrideFiles) continue;

            $this->storage->put($innerPath, Storage::disk('builder')->get($templateFilePath));
        }

        //thumbnail
        $this->storage->put("$projectPath/thumbnail.png", Storage::disk('builder')->get(str_replace('builder', '', $templateData['thumbnail'])));
    }

    /**
     * Load all pages for specified project.
     *
     * @param string $path
     * @return Collection
     */
    private function loadProjectPages($path)
    {
        return collect($this->storage->files($path))->filter(function ($path) {
            return Str::contains($path, '.html');
        })->map(function ($path) {
            return ['name' => basename($path, '.html'), 'html' => $this->storage->get($path)];
        })->values();
    }

    /**
     * Add specified custom element css to the project.
     *
     * @param string $projectPath
     * @param string $customElementCss
     */
    private function addCustomElementCss($projectPath, $customElementCss)
    {
        $path = "$projectPath/css/custom_elements.css";

        try {
            $contents = $this->storage->get($path);
        } catch (FileNotFoundException $e) {
            $contents = '';
        }

        //if this custom element css is already added, bail
        if ($contents && Str::contains($contents, $customElementCss)) return;

        $contents = "$contents\n$customElementCss";

        $this->storage->put($path, $contents);
    }

    /**
     * Get contents of specified builder asset file.
     *
     * @param string $path
     * @return string
     * @throws FileNotFoundException
     */
    private function getBuilderAsset($path)
    {
        return Storage::disk('builder')->get($path);
    }
}
