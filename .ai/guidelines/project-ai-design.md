## Project AI Design Rules

- Never propose or implement phrase-level hardcoding as the primary solution for AI intent detection, conversation follow-ups, language handling, or catalog/search behavior. Do not solve ambiguous context by checking for specific customer phrases or wording variants. Prefer explicit conversation state, structured intent classification, typed tool parameters, semantic constraints, or changes to the tool contract. If a keyword guard is truly unavoidable as a temporary production safety patch, state that it is a stopgap and ask for approval before implementing it.
