# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.3] - 2026-01-05
### Added
- Automatic accessor/virtual field detection in the semantic query builder so natural-language filters map to the correct physical columns without manual overrides.
- Configurable `field_overrides` section to let applications explicitly map edge-case attributes when needed.

### Fixed
- Guarded against empty-string sentinel filters by translating them into proper NULL/NOT NULL predicates, preventing malformed SQL and incorrect fallbacks.

## [0.2.2] - 2025-12-??
### Added
- Initial public release with static analysis pipeline, multilingual parser, and optional LLM fallback.
