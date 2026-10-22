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
    case MapBackendUnsupported = 'map_backend_unsupported';
    case AnchorMissing = 'anchor_missing';
    case AnchorAmbiguous = 'anchor_ambiguous';
    case EditsOverlap = 'edits_overlap';
    case ApiChangeRefused = 'api_change_refused';
    case TargetExists = 'target_exists';
    case ApplyFailed = 'apply_failed';
    case Failed = 'failed';

    /** @return 'fix_arguments'|'provide_input'|'replan'|'refresh_map_and_replan'|'rebuild_map_with_phpstan'|'reread_and_retry'|'disambiguate_anchor'|'merge_edits'|'use_map_plan_or_acknowledge'|'edit_existing_file'|'inspect_working_tree'|'report' */
    public function nextAction(): string
    {
        return match ($this) {
            self::Usage => 'fix_arguments',
            self::InputNotFound => 'provide_input',
            self::PlanMalformed, self::PlanRefused, self::PlanUnsupported => 'replan',
            self::EvidenceStale, self::EvidenceChanged => 'refresh_map_and_replan',
            self::MapBackendUnsupported => 'rebuild_map_with_phpstan',
            self::AnchorMissing => 'reread_and_retry',
            self::AnchorAmbiguous => 'disambiguate_anchor',
            self::EditsOverlap => 'merge_edits',
            self::ApiChangeRefused => 'use_map_plan_or_acknowledge',
            self::TargetExists => 'edit_existing_file',
            self::ApplyFailed => 'inspect_working_tree',
            self::Failed => 'report',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Usage => 'Fix the command line; `agent-edit help` lists every accepted option.',
            self::InputNotFound => 'Pass an existing file or directory; paths are resolved against the project root.',
            self::PlanMalformed => 'The plan document is not valid for its contract. Fix it (`agent-edit schema` prints the source_edit_plan schema) or, for an agent-map plan, produce it again with the matching `agent-map ...-plan --format=json` command.',
            self::PlanUnsupported => 'Run `agent-edit capabilities` to see the executable plan types and contract versions; use only those.',
            self::PlanRefused => 'Nothing was changed. The plan is blocked, unsafe or needs review; resolve what `details` lists, then produce a new plan.',
            self::EvidenceStale => 'Nothing was changed. Rebuild the Map (`agent-map refresh`), produce the plan again and re-run the apply.',
            self::EvidenceChanged => 'Nothing was changed. The source moved since the plan was made; refresh the Map, produce the plan again and re-run the apply.',
            self::MapBackendUnsupported => 'Nothing was changed. This plan needs a Map built with PHPStan (`agent-map build --backend=phpstan`, which needs phpstan/phpstan installed); rebuild it, produce the plan again and re-run.',
            self::AnchorMissing => 'Nothing was changed. The text to replace is not in the file as given, or the file changed since it was read; re-read the file, copy the exact current text and retry.',
            self::AnchorAmbiguous => 'Nothing was changed. `find` matches more than once; extend it with surrounding text, or pass `occurrence` (1-based) or `all`.',
            self::EditsOverlap => 'Nothing was changed. Two edits touch the same text; merge them into one edit or anchor them on disjoint text.',
            self::ApiChangeRefused => 'Nothing was changed. The edit removes or changes a class, function or non-private method signature; use the matching agent-map plan, or set allow_signature_change after checking the callers yourself.',
            self::TargetExists => 'Nothing was changed. A file to create already exists; change it with an edit or pick another path.',
            self::ApplyFailed => 'The transaction rolled back every source. Inspect `git status` and the receipt bundle before trying again.',
            self::Failed => 'No automated recovery is known. Inspect `git status` and the newest bundle under `.agent-edit/receipts` before retrying, and report the message if it persists.',
        };
    }
}
