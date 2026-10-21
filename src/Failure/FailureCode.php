<?php

declare(strict_types=1);

namespace voku\AgentEdit\Failure;

/**
 * Stable, machine-readable classification of why `agent-edit` refused or failed. The code names the cause, `nextAction()`
 * names the only recovery that is legal for it. Neither carries authority: a caller still decides whether to proceed.
 */
enum FailureCode: string
{
    case Usage = 'usage';
    case InputNotFound = 'input_not_found';
    case PlanMalformed = 'plan_malformed';
    case PlanUnsupported = 'plan_unsupported';
    case PlanRefused = 'plan_refused';
    case EvidenceStale = 'evidence_stale';
    case EvidenceChanged = 'evidence_changed';
    case ApplyFailed = 'apply_failed';
    case Failed = 'failed';

    /** @return 'fix_arguments'|'provide_input'|'replan'|'refresh_map_and_replan'|'inspect_working_tree'|'report' */
    public function nextAction(): string
    {
        return match ($this) {
            self::Usage => 'fix_arguments',
            self::InputNotFound => 'provide_input',
            self::PlanMalformed, self::PlanRefused, self::PlanUnsupported => 'replan',
            self::EvidenceStale, self::EvidenceChanged => 'refresh_map_and_replan',
            self::ApplyFailed => 'inspect_working_tree',
            self::Failed => 'report',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Usage => 'Fix the command line; `agent-edit help` lists every accepted option.',
            self::InputNotFound => 'Pass an existing file or directory; paths are resolved against the project root.',
            self::PlanMalformed => 'The plan file is not a valid agent-map plan document; produce it again with the matching `agent-map ...-plan --format=json` command.',
            self::PlanUnsupported => 'Run `agent-edit capabilities` to see the executable plan types and contract versions; use only those.',
            self::PlanRefused => 'Nothing was changed. The plan is blocked, unsafe or needs review; resolve what `details` lists, then produce a new plan.',
            self::EvidenceStale => 'Nothing was changed. Rebuild the Map (`agent-map refresh`), produce the plan again and re-run the apply.',
            self::EvidenceChanged => 'Nothing was changed. The source moved since the plan was made; refresh the Map, produce the plan again and re-run the apply.',
            self::ApplyFailed => 'The transaction rolled back every source. Inspect `git status` and the receipt bundle before trying again.',
            self::Failed => 'No automated recovery is known. Inspect `git status` and the newest bundle under `.agent-edit/receipts` before retrying, and report the message if it persists.',
        };
    }
}
