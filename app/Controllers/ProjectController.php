<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ScreenplayService;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;

final class ProjectController extends BaseController
{
    public function __construct(\App\Services\AuthService $auth, private readonly ScreenplayService $screenplays)
    {
        parent::__construct($auth);
    }

    public function dashboard(): void
    {
        $user = $this->user();
        View::render('dashboard', [
            'pageTitle' => 'Your screenplays',
            'user' => $user,
            'projects' => $this->screenplays->projectsForUser((int) $user['id']),
            'archivedProjects' => $this->screenplays->projectsForUser((int) $user['id'], true),
        ]);
    }

    public function createProject(): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $project = $this->screenplays->createProject((int) $user['id'], $this->requestText('title'), $this->requestText('project_type') ?: 'film', $_POST['description'] ?? null);
        Flash::set('success', 'Project created. Add a scene to start writing.');
        Http::redirect('/projects/' . $project['uuid']);
    }

    public function workspace(string $projectUuid): void
    {
        $user = $this->user();
        $data = $this->screenplays->workspace((int) $user['id'], $projectUuid);
        $data['pageTitle'] = $data['project']['title'];
        $data['user'] = $user;
        View::render('workspace', $data);
    }

    public function updateProject(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->updateProject((int) $user['id'], $projectUuid, $this->requestText('title'), $this->requestText('project_type'), $_POST['description'] ?? null);
        Flash::set('success', 'Project details saved.');
        Http::redirect('/projects/' . $projectUuid);
    }

    public function archiveProject(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->setProjectStatus((int) $user['id'], $projectUuid, $this->requestText('status'));
        Flash::set('success', 'Project status updated.');
        Http::redirect('/');
    }

    public function deleteProject(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->deleteProject((int) $user['id'], $projectUuid, $this->requestText('confirmation'));
        Flash::set('success', 'Project permanently deleted.');
        Http::redirect('/');
    }

    public function createEpisode(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->createEpisode((int) $user['id'], $projectUuid, $this->requestText('title'));
        Http::redirect('/projects/' . $projectUuid);
    }

    public function createAct(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->createAct((int) $user['id'], $projectUuid, $this->requestText('title'), $this->requestText('episode_uuid') ?: null);
        Http::redirect('/projects/' . $projectUuid);
    }

    public function createCharacter(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->createCharacter((int) $user['id'], $projectUuid, $this->requestText('name'), $_POST['description'] ?? null);
        Flash::set('success', 'Character added.');
        Http::redirect('/projects/' . $projectUuid);
    }

    public function createScene(string $projectUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $scene = $this->screenplays->createScene((int) $user['id'], $projectUuid, $this->requestText('heading'), $this->requestText('episode_uuid') ?: null, $this->requestText('act_uuid') ?: null);
        Http::redirect('/projects/' . $projectUuid . '/scenes/' . $scene['uuid']);
    }

    public function editor(string $projectUuid, string $sceneUuid): void
    {
        $user = $this->user();
        $data = $this->screenplays->sceneEditor((int) $user['id'], $projectUuid, $sceneUuid);
        $data['pageTitle'] = $data['scene']['heading'] ?: 'Scene editor';
        $data['user'] = $user;
        View::render('editor', $data);
    }

    public function read(string $projectUuid): void
    {
        $user = $this->user();
        $project = $this->screenplays->project((int) $user['id'], $projectUuid);
        View::render('read', [
            'pageTitle' => $project['title'] . ' — screenplay',
            'user' => $user,
            'project' => $project,
            'scenes' => $this->screenplays->screenplay((int) $user['id'], $projectUuid),
        ]);
    }

    public function duplicateScene(string $projectUuid, string $sceneUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $scene = $this->screenplays->duplicateScene((int) $user['id'], $projectUuid, $sceneUuid);
        Flash::set('success', 'Scene duplicated.');
        Http::redirect('/projects/' . $projectUuid . '/scenes/' . $scene['uuid']);
    }

    public function createBlock(string $projectUuid, string $sceneUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->createBlock((int) $user['id'], $projectUuid, $sceneUuid, $this->requestText('block_type') ?: 'action');
        Http::redirect('/projects/' . $projectUuid . '/scenes/' . $sceneUuid . '#blocks');
    }

    public function deleteBlock(string $projectUuid, string $sceneUuid, string $blockUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->deleteBlock((int) $user['id'], $projectUuid, $blockUuid);
        Http::redirect('/projects/' . $projectUuid . '/scenes/' . $sceneUuid . '#blocks');
    }

    public function moveBlock(string $projectUuid, string $sceneUuid, string $blockUuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->moveBlock((int) $user['id'], $projectUuid, $blockUuid, $this->requestText('direction') === 'up' ? -1 : 1);
        Http::redirect('/projects/' . $projectUuid . '/scenes/' . $sceneUuid . '#blocks');
    }

    public function moveItem(string $projectUuid, string $kind, string $uuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->moveOrderedItem((int) $user['id'], $projectUuid, $kind, $uuid, $this->requestText('direction') === 'up' ? -1 : 1);
        Http::redirect('/projects/' . $projectUuid);
    }

    public function renameItem(string $projectUuid, string $kind, string $uuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->renameItem((int) $user['id'], $projectUuid, $kind, $uuid, $this->requestText('title'));
        Flash::set('success', ucfirst($kind) . ' renamed.');
        Http::redirect('/projects/' . $projectUuid);
    }

    public function deleteItem(string $projectUuid, string $kind, string $uuid): void
    {
        $this->requirePost(); $this->csrf(); $user = $this->user();
        $this->screenplays->deleteItem((int) $user['id'], $projectUuid, $kind, $uuid);
        Flash::set('success', ucfirst($kind) . ' deleted.');
        Http::redirect('/projects/' . $projectUuid);
    }
}
