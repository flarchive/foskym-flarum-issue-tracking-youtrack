<?php
namespace FoskyM\IssueTrackingYoutrack;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;

use FoskyM\IssueTracking\AbstractPlatformProvider;
use FoskyM\IssueTracking\AbstractIssue;
use FoskyM\IssueTracking\AbstractProgress;
use FoskyM\IssueTracking\AbstractUser;
use FoskyM\IssueTracking\AbstractLabel;

use Illuminate\Support\MessageBag;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Validation\Factory;

use FoskyM\IssueTrackingYoutrack\YouTrack;

class PlatformProvider extends AbstractPlatformProvider
{
    public $key = "foskym-issue-tracking-youtrack";
    public $name = "YouTrack";

    const FIELDS = "id,idReadable,summary,description,reporter(login,name,email),tags,updated,resolved,created,comments(id,author(login),text,created,updated),customFields(id,name,value(avatarUrl,email,buildLink,color(id,background,foreground),fullName,id,isResolved,localizedName,login,minutes,name,presentation,text))";

    public function availableSettings(): array
    {
        return [
            'url' => 'required|url',
            'token' => 'required',
            'project' => 'required',
            'state_field' => '',
            'resolved_state' => '',
            'fields_mapping' => '',
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

    private function buildIssue(array $issue): AbstractIssue
    {
        $settings = $this->getSettings();

        $state = new AbstractLabel();
        $priority = new AbstractLabel();
        $type = new AbstractLabel();

        $assignee = new AbstractUser();

        $author = new AbstractUser();
        $author->username = $issue['reporter']['login'] ?? '';
        $author->display_name = $issue['reporter']['name'] ?? '';
        $author->email = $issue['reporter']['email'] ?? '';

        $SimpleIssueCustomFields = [];

        foreach ($issue['customFields'] as $field) {
            if ($field['name'] == 'State') {
                $state->name = $field['value']['localizedName'];
                $state->foreground = $field['value']['color']['foreground'];
                $state->background = $field['value']['color']['background'];
                $state->type = $field['value']['name'];
            } else if ($field['name'] == 'Priority') {
                $priority->name = $field['value']['localizedName'];
                $priority->foreground = $field['value']['color']['foreground'];
                $priority->background = $field['value']['color']['background'];
                $priority->type = $field['value']['name'];
            } else if ($field['name'] == 'Type') {
                $type->name = $field['value']['localizedName'];
                $type->foreground = $field['value']['color']['foreground'];
                $type->background = $field['value']['color']['background'];
                $type->type = $field['value']['name'];
            } else if ($field['name'] === 'Assignee') {
                $assignee->username = $field['value']['login'] ?? '';
                $assignee->display_name = $field['value']['fullName'] ?? '';
                $assignee->email = $field['value']['email'] ?? '';
            } else if ($field['$type'] === 'SimpleIssueCustomField') {
                $SimpleIssueCustomFields[$field['name']] = $field['value'];
            }
        }

        $model = new AbstractIssue();
        $model->id = $issue['id'];
        $model->slug = $issue['idReadable'];
        $model->title = $issue['summary'];
        $model->description = $issue['description'] ?? $issue['summary'];
        $model->author = $author;
        $model->assignee = $assignee;
        $model->state = $state;
        $model->priority = $priority;
        $model->type = $type;
        $model->updated_at = $issue['updated'] / 1000;
        $model->resolved_at = $issue['resolved'] / 1000;
        $model->created_at = $issue['created'] / 1000;
        $model->is_resolved = $this->isIssueResolved($model);
        $model->progress = $this->calculateIssueProgress($model);
        $model->link = $settings['url'] . '/issue/' . $model->slug;

        if (is_null($settings['fields_mapping'])) {
            return $model;
        }

        $mappingFields = explode(',', $settings['fields_mapping']);
        $mappingFields = array_filter($mappingFields, function ($field) {
            return strpos($field, ':') !== false;
        });
        array_map(function ($field) use ($SimpleIssueCustomFields, $model) {
            list($key, $value) = explode(':', $field);
            if (isset($SimpleIssueCustomFields[$key])) {
                list($object, $varaible) = explode('.', $value);
                if (isset($model->$object->$varaible)) {
                    $model->$object->$varaible = $SimpleIssueCustomFields[$key];
                }
            }
        }, $mappingFields);

        return $model;
    }

    public function getIssueList(string $sort = 'latest', int $skip = 0, int $limit = 15): array
    {
        $settings = $this->getSettings();
        $youtrack = new YouTrack(
            $settings['url'],
            $settings['token'],
            $settings['project']
        );

        $query = 'project: ' . $settings['project'] . ' sort by: ';
        if ($sort == 'latest') {
            $query .= '{updated} desc';
        } else if ($sort == 'newest') {
            $query .= '{created} desc';
        } else if ($sort == 'oldest') {
            $query .= '{created} asc';
        }

        $issues = $youtrack->get('/issues?fields=' . self::FIELDS . '&query=' . urlencode($query) . '&$skip=' . $skip . '&$top=' . $limit);
        // map issues to the required format
        return array_map(function ($issue) {
            $model = $this->buildIssue($issue);
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
        $issue = $youtrack->get('/issues/' . $issueId . '?fields=' . self::FIELDS);
        
        $model = $this->buildIssue($issue);
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
        $issue = $youtrack->post('/admin/projects/' . $settings['project'] . '/issues?fields=' . self::FIELDS, [
            'summary' => $title,
            'description' => $description
        ]);

        $model = $this->buildIssue($issue);
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
        $issues = $this->getIssueList('latest', 0, 1000);

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

    // public function getLatestProgress(): AbstractProgress
    // {
    //     $settings = $this->getSettings();
    //     $youtrack = new YouTrack(
    //         $settings['url'],
    //         $settings['token'],
    //         $settings['project']
    //     );

    //     $total = $youtrack->post('/issuesGetter/count?fields=count', [
    //         'query' => 'project: ' . $settings['project']
    //     ])['count'];
    //     $resolved  = $youtrack->post('/issuesGetter/count?fields=count', [
    //         'query' => 'project: ' . $settings['project'] . ' #Resolved'
    //     ])['count'];

    //     $query = urlencode('project: ' . $settings['project'] . ' sort by: {updated} desc');
    //     $issue = $youtrack->get('/issues?query=' . $query . '&$top=1&$skip=0&fields=id,updated,resolved,created');

    //     $progress = new AbstractProgress();

    //     $progress->updated_at = $issue[0]['updated'] / 1000;
    //     $progress->total = $total;
    //     $progress->resolved = $resolved;
    //     $progress->unresolved = $total - $resolved;

    //     return $progress;
    // }
}