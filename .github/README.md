# GitHub Configuration

This directory contains configuration files for GitHub features and integrations.

## Copilot Instructions

### Overview
The `copilot-instructions.md` file provides comprehensive guidelines for GitHub Copilot coding agents working on this repository. These instructions help ensure consistent code quality, adherence to project standards, and alignment with best practices.

### What's Configured
- **YAML Frontmatter**: Defines scope for which file types the instructions apply to
- **Project Overview**: Context about the PHP Tacview analysis tool
- **Code Standards**: PHP 8.2+ standards, PSR-12 compliance, strict typing requirements
- **Domain Knowledge**: Tacview format and military aviation context
- **Testing & Validation**: Comprehensive checklists and test commands
- **Common Task Guidelines**: Step-by-step guidance for features, bugs, performance, and i18n
- **Security & Performance**: Key considerations for this specific application
- **Helpful Links**: References to official documentation and standards

### For Developers
When working with GitHub Copilot coding agent on issues in this repository:
1. Copilot will automatically read and follow these instructions
2. The instructions emphasize checking CHANGELOG.md before making changes
3. Always test with real Tacview XML files after making changes
4. Keep all 10 language files synchronized

### Resources
- [GitHub Copilot Best Practices](https://docs.github.com/en/copilot/tutorials/coding-agent/get-the-best-results)
- [Custom Instructions Documentation](https://docs.github.com/en/copilot/customizing-copilot/adding-custom-instructions-for-github-copilot)

### Maintenance
The copilot-instructions.md file should be updated when:
- Project structure changes significantly
- New coding standards are adopted
- Deployment targets change
- New critical workflows are established

Last Updated: November 5, 2025
