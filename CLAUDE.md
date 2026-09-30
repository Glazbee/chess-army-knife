# Project

This is a PHP 7.4 Wordpress plugin for doing chess-related things. The plugin should follow WordPress conventions and integrate cleanly with the WordPress ecosystem. Keep the implementation simple, maintainable and easy to extend.

# Code Style
- Use tabs for indentation (required by WordPress Coding Standards and enforced by PHPCS and Prettier)
- Follow WordPress Coding Standards
- Use meaningful, descriptive names for functions, classes and variables
- Prefer explicit, readable code over clever solutions
- Prefer simple one-line comments
- Document non-obvious logic and public functions where appropriate

# Architecture
- Follow the existing project structure
- Avoid unnecesary architectural changes
- Use Wordpress APIs and built-in functionality. Do not reinvent the wheel.
- Use hooks, filters and actions where appropriate
- Do not introduce dependencies unless there is a clear justification.
- Ensure all plugin functionality is properly namespaced to avoid naming conflicts

# Security
- Sanitise and validate all user input
- Escape all output using the appropriate Wordpress escaping functions
- Use nonces for forms and actions where appropriate
- Use `$wpdb->prepare()` for database queries involving dynamic values
- Enforce appropriate checks for privileged operations
- Never expose secrets, credentials or sensitive information

# Compatibility
- PHP 7.4 is the minimum supported version
- Wordpress 7.1.2 is the minimum version
- Avoid using PHP features introduced after PHP 7.4
- Ensure functionality does not rely on a particular theme

# APIs and integration
- Follow the documented contracts of external APIs
- Always cache results with a reasonable expiry (6 hours typically)
- Reuse existing cache patterns where possible
- Handle API errors, timeouts and unexpected responses gracefully
- Do not hardcode credentials, URLs or environment-specific configurations
- Avoid making assumptions about external API availablity or response formats

# Testing
- Add or update tests when introducing or changing functionality
- Test both successful and non-successful execution paths
- Verify that changes do not break existing functionality
- Run the available project tests and static analysis tools where aplicable
- Do not run integration tests with Wordpress. Let Github CI do this

# Development
- Make the smallest reasonable change to achieve the requested functionality
- Do not modify unrelated files or introduce unnecesary refactoring
- Do not assume undocumented behaviour; inspect the code or relevant API documentation first
- Update relevant documentation when changing public functionality, configuration or integrations
- We are in development mode. Do not bump the schema version, as no live installs exist


# ECF API
- The ECF API has a maximum processing time of 10 minutes per day. We must cache results

# LMS API
- The live LMS API is located at https://lms.englishchess.org.uk/