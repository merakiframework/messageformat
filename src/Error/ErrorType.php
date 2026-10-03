<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

/**
 * Every error the specification names.
 *
 * All fourteen, even though this milestone can only raise two of them. The rest are here because
 * the conformance suite identifies expected errors by these slugs and the runner has to be able
 * to name any of them — a partial enum would make an unimplemented error indistinguishable from
 * a misspelled one.
 *
 * The suite is narrower than the specification, in two steps. Its schema enumerates thirteen of
 * these, leaving out `unsupported-operation`, so no fixture can expect that one however the
 * library behaves; it is kept because errors.md defines it and an ICU failure will need somewhere
 * to go. The fixtures then use twelve of the thirteen, `bad-variant-key` being allowed but not
 * currently exercised by any case.
 *
 * The slug is the suite's spelling, not the specification's prose name: errors.md writes
 * "Bad Operand" and the fixtures write "bad-operand".
 */
enum ErrorType: string
{
	case Syntax = 'syntax-error';

	case VariantKeyMismatch = 'variant-key-mismatch';
	case MissingFallbackVariant = 'missing-fallback-variant';
	case MissingSelectorAnnotation = 'missing-selector-annotation';
	case DuplicateDeclaration = 'duplicate-declaration';
	case DuplicateOptionName = 'duplicate-option-name';
	case DuplicateVariant = 'duplicate-variant';

	case UnresolvedVariable = 'unresolved-variable';
	case UnknownFunction = 'unknown-function';
	case BadSelector = 'bad-selector';

	case BadOperand = 'bad-operand';
	case BadOption = 'bad-option';
	case BadVariantKey = 'bad-variant-key';
	case UnsupportedOperation = 'unsupported-operation';

	public function slug(): string
	{
		return $this->value;
	}

	public function category(): ErrorCategory
	{
		return match ($this) {
			self::Syntax => ErrorCategory::Syntax,

			self::VariantKeyMismatch,
			self::MissingFallbackVariant,
			self::MissingSelectorAnnotation,
			self::DuplicateDeclaration,
			self::DuplicateOptionName,
			self::DuplicateVariant => ErrorCategory::DataModel,

			self::UnresolvedVariable,
			self::UnknownFunction,
			self::BadSelector => ErrorCategory::Resolution,

			self::BadOperand,
			self::BadOption,
			self::BadVariantKey,
			self::UnsupportedOperation => ErrorCategory::MessageFunction,
		};
	}
}
