<?php

declare(strict_types=1);

namespace mglaman\DrupalOrg\Project;

enum ProjectPublishStep: string
{
    case WAIT_FOR_REPOSITORY = 'WAIT_FOR_REPOSITORY';
    case PUBLISH_SOURCE = 'PUBLISH_SOURCE';
    case CREATE_TAG = 'CREATE_TAG';
    case CREATE_RELEASE = 'CREATE_RELEASE';
    case VERIFY_RELEASE = 'VERIFY_RELEASE';
    case VERIFY_COMPOSER = 'VERIFY_COMPOSER';
    case COMPLETE = 'COMPLETE';
}
