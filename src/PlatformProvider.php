<?php
namespace FoskyM\IssueTrackingYoutrack;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;

use FoskyM\IssueTracking\AbstractPlatformProvider;
use FoskyM\IssueTracking\AbstractIssue;
use FoskyM\IssueTracking\AbstractProgress;
use Illuminate\Support\MessageBag;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Validation\Factory;

use FoskyM\IssueTrackingYoutrack\YouTrack;

class PlatformProvider extends AbstractPlatformProvider
{
    public $key = "foskym-issue-tracking-youtrack";
    public $name = "YouTrack";

    public function availableSettings(): array
    {
        return [
            'url' => 'required|url',
            'token' => 'required',
            'project' => 'required',
            'state_field' => 'required',
            'resolved_state' => 'required',
        ];
    }

    public function testConnection(): bool
    {
        $settings = $this->getSettings();
        $youtrack = new YouTrack(
            $settings['url'],
            $settings['token'],
            $settings['project']
        );
        
        $result = $youtrack->statusCode('/admin/projects/' . $settings['project']);
        return $result == 200;
    }

    public function getIssueList(string $sort = 'latest'): array
    {
        $settings = $this->getSettings();
        $youtrack = new YouTrack(
            $settings['url'],
            $settings['token'],
            $settings['project']
        );
        // Fetch issues from the platform
        // $issues = $youtrack->request('GET', '/issues?fields=id,idReadable,summary,description,reporter(login)');
        // $issues = $youtrack->request('GET', '/admin/projects/' . $settings['project'] . '/issues?&query=sort by: {updated} desc&fields=id,idReadable,summary,description,reporter(login),tags,updated,resolved,created,comments(id,author(login),text,created,updated),customFields(id,name,value(avatarUrl,buildLink,color(id,background,foreground),fullName,id,isResolved,localizedName,login,minutes,name,presentation,text))');
        $query = 'project: ' . $settings['project'] . ' sort by: ';
        if ($sort == 'latest') {
            $query .= '{updated} desc';
        } else if ($sort == 'newest') {
            $query .= '{created} desc';
        } else if ($sort == 'oldest') {
            $query .= '{created} asc';
        }

        $issueNodes = $youtrack->get('/sortedIssues?query=' . urlencode($query) . '&fields=tree(id)');
        $payload = [];
        foreach ($issueNodes['tree'] as $node) {
            $payload[] = [
                'id' => $node['id']
            ];
        }
        $issues = $youtrack->post('/issuesGetter?fields=id,idReadable,summary,description,reporter(login),tags,updated,resolved,created,comments(id,author(login),text,created,updated),customFields(id,name,value(avatarUrl,buildLink,color(id,background,foreground),fullName,id,isResolved,localizedName,login,minutes,name,presentation,text))', $payload);
        // print_r($issues->toArray());
        // return [];
        // map issues to the required format
        return array_map(function ($issue) {
            $state = [];
            $priority = [];
            $type = [];

            foreach ($issue['customFields'] as $field) {
                if ($field['name'] == 'State') {
                    $state['name'] = $field['value']['localizedName'];
                    $state['foreground'] = $field['value']['color']['foreground'];
                    $state['background'] = $field['value']['color']['background'];
                    $state['type'] = $field['value']['name'];
                } else if ($field['name'] == 'Priority') {
                    $priority['name'] = $field['value']['localizedName'];
                    $priority['foreground'] = $field['value']['color']['foreground'];
                    $priority['background'] = $field['value']['color']['background'];
                    $priority['type'] = $field['value']['name'];
                } else if ($field['name'] == 'Type') {
                    $type['name'] = $field['value']['localizedName'];
                    $type['foreground'] = $field['value']['color']['foreground'];
                    $type['background'] = $field['value']['color']['background'];
                    $type['type'] = $field['value']['name'];
                }
            }
            $model = new AbstractIssue();
            $model->id = $issue['id'];
            $model->slug = $issue['idReadable'];
            $model->title = $issue['summary'];
            $model->description = $issue['description'];
            $model->author = $issue['reporter']['login'];
            $model->state = $state;
            $model->priority = $priority;
            $model->type = $type;
            $model->updated_at = $issue['updated'] / 1000;
            $model->resolved_at = $issue['resolved'] / 1000;
            $model->created_at = $issue['created'] / 1000;
            $model->is_resolved = $this->isIssueResolved($model);
            $model->progress = $this->calculateIssueProgress($model);
            return $model;
        }, $issues);
    }

    public function getIssue(string $issueId): AbstractIssue
    {
        $settings = $this->getSettings();
        $youtrack = new YouTrack(
            $settings['url'],
            $settings['token'],
            $settings['project']
        );
        $issue = $youtrack->get('/issues/' . $issueId . '?fields=id,idReadable,summary,description,reporter(login),tags,updated,resolved,created,comments(id,author(login),text,created,updated),customFields(id,name,value(avatarUrl,buildLink,color(id,background,foreground),fullName,id,isResolved,localizedName,login,minutes,name,presentation,text))');

        $state = [];
        $priority = [];
        $type = [];

        foreach ($issue['customFields'] as $field) {
            if ($field['name'] == 'State') {
                $state['name'] = $field['value']['localizedName'];
                $state['foreground'] = $field['value']['color']['foreground'];
                $state['background'] = $field['value']['color']['background'];
                $state['type'] = $field['value']['name'];
            } else if ($field['name'] == 'Priority') {
                $priority['name'] = $field['value']['localizedName'];
                $priority['foreground'] = $field['value']['color']['foreground'];
                $priority['background'] = $field['value']['color']['background'];
                $priority['type'] = $field['value']['name'];
            } else if ($field['name'] == 'Type') {
                $type['name'] = $field['value']['localizedName'];
                $type['foreground'] = $field['value']['color']['foreground'];
                $type['background'] = $field['value']['color']['background'];
                $type['type'] = $field['value']['name'];
            }
        }

        $model = new AbstractIssue();
        $model->id = $issue['id'];
        $model->slug = $issue['idReadable'];
        $model->title = $issue['summary'];
        $model->description = $issue['description'];
        $model->author = $issue['reporter']['login'];
        $model->state = $state;
        $model->priority = $priority;
        $model->type = $type;
        $model->updated_at = $issue['updated'] / 1000;
        $model->resolved_at = $issue['resolved'] / 1000;
        $model->created_at = $issue['created'] / 1000;
        $model->is_resolved = $this->isIssueResolved($model);
        $model->progress = $this->calculateIssueProgress($model);
        return $model;
    }

    public function createIssue(User $user, string $title, string $description): AbstractIssue
    {
        $settings = $this->getSettings();
        $youtrack = new YouTrack(
            $settings['url'],
            $settings['token'],
            $settings['project']
        );
        $issue = $youtrack->post('/admin/projects/' . $settings['project'] . '/issues?fields=id,idReadable,summary,description,reporter(login),tags,updated,resolved,created,comments(id,author(login),text,created,updated),customFields(id,name,value(avatarUrl,buildLink,color(id,background,foreground),fullName,id,isResolved,localizedName,login,minutes,name,presentation,text))', [
            'summary' => $title,
            'description' => $description
        ]);

        $state = [];
        $priority = [];
        $type = [];

        foreach ($issue['customFields'] as $field) {
            if ($field['name'] == 'State') {
                $state['name'] = $field['value']['localizedName'];
                $state['foreground'] = $field['value']['color']['foreground'];
                $state['background'] = $field['value']['color']['background'];
                $state['type'] = $field['value']['name'];
            } else if ($field['name'] == 'Priority') {
                $priority['name'] = $field['value']['localizedName'];
                $priority['foreground'] = $field['value']['color']['foreground'];
                $priority['background'] = $field['value']['color']['background'];
                $priority['type'] = $field['value']['name'];
            } else if ($field['name'] == 'Type') {
                $type['name'] = $field['value']['localizedName'];
                $type['foreground'] = $field['value']['color']['foreground'];
                $type['background'] = $field['value']['color']['background'];
                $type['type'] = $field['value']['name'];
            }
        }

        $model = new AbstractIssue();
        $model->id = $issue['id'];
        $model->slug = $issue['idReadable'];
        $model->title = $issue['summary'];
        $model->description = $issue['description'];
        $model->author = $issue['reporter']['login'];
        $model->state = $state;
        $model->priority = $priority;
        $model->type = $type;
        $model->updated_at = $issue['updated'] / 1000;
        $model->resolved_at = $issue['resolved'] / 1000;
        $model->created_at = $issue['created'] / 1000;
        $model->is_resolved = $this->isIssueResolved($model);
        $model->progress = $this->calculateIssueProgress($model);
        return $model;

    }

    public function createComment(User $user, string $issueId, string $content): bool
    {
        $settings = $this->getSettings();
        $youtrack = new YouTrack(
            $settings['url'],
            $settings['token'],
            $settings['project']
        );

        try {
            $result = $youtrack->request('/issues/' . $issueId . '/comments', 'POST', [
                'text' => "**{$user->username}**\n{$content}",
            ]);

            return $result['code'] == 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function calculateIssueProgress(AbstractIssue $issue): float
    {
        $settings = $this->getSettings();
        $stateField = $settings['state_field'];
        $resolvedState = $settings['resolved_state'];
        $stateField = str_replace('[Resolved_State]', $resolvedState, $stateField);
        $stateField = explode(',', $stateField);
        $stateField = array_map(function ($state) {
            return trim($state);
        }, $stateField);
        $order = 0;
        $total = count($stateField);
        foreach ($stateField as $state) {
            $order++;
            if (strpos($state, '|') !== false) {
                $state = explode('|', $state);
                if (in_array($issue->state['type'], $state)) {
                    return ($order - 1) / ($total - 1);
                }
            } else if ($state == $issue->state['type']) {
                return ($order - 1) / ($total - 1);
            }
        }
        return 0;
    }

    public function isIssueResolved(AbstractIssue $issue): bool
    {
        $settings = $this->getSettings();
        $resolvedState = $settings['resolved_state'];
        $resolvedState = explode('|', $resolvedState);
        $resolvedState = array_map(function ($state) {
            return trim($state);
        }, $resolvedState);
        if (in_array($issue->state['type'], $resolvedState)) {
            return true;
        }
        return false;
    }

    public function getLatestProgress(): AbstractProgress
    {
        $issues = $this->getIssueList('latest');

        $progress = new AbstractProgress();

        $progress->updated_at = $issues[0]->updated_at;
        array_map(function ($issue) use ($progress) {
            $progress->total++;
            if ($this->isIssueResolved($issue)) {
                $progress->resolved++;
            } else {
                $progress->unresolved++;
            }
        }, $issues);

        return $progress;
    }
}