<?php

namespace App\Services\Tasks\Features;

use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;

/**
 * Keeps task features consistent when repositories move between projects.
 */
class FeatureScopeMigrator
{
    /**
     * A repository was newly assigned to a project.
     */
    public function repositoryJoined(GitRepository $repository, Project $project): void
    {
        // Filled in by the feature-grouping task.
    }

    /**
     * A repository is leaving a project, by unassignment, move or project deletion.
     */
    public function repositoryLeft(GitRepository $repository, Project $old): void
    {
        // Filled in by the feature-grouping task.
    }
}
