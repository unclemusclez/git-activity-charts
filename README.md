Scopes

To ensure your WordPress plugin can fetch commit activity data from GitHub, GitLab, Gitea, and Bitbucket, including private repositories, you’ll need to configure API keys with specific permissions (or "scopes") for each service. Below, I’ll outline the required permissions for each provider based on the API endpoints we’re using in the plugin.

1. GitHub

   API Endpoint: GET /repos/{owner}/{repo}/stats/commit_activity
   Required Permissions:
   repo Scope: Grants full access to public and private repositories, including commit data.
   This includes repo:status, repo_deployment, public_repo, and repo:invite, but for our purposes, the key part is access to private repo data.
   Why: The commit_activity endpoint requires access to repository details and commit statistics. Without the repo scope, you can only access public repos, and even then, rate limits are stricter for unauthenticated requests.
   How to Generate:
   Go to GitHub > Settings > Developer settings > Personal access tokens > Tokens (classic).
   Create a new token, check the repo scope (select all sub-options if needed), and copy the token.
   Use this token as the api_key in the plugin settings.

2. GitLab

   API Endpoint: GET /api/v4/projects/{id}/repository/commits
   Required Permissions:
   api Scope: Provides full read/write access to the GitLab API, including repository commits.
   This scope allows access to private repositories and commit history.
   Alternative: If you only need read access, the read_api scope might suffice, but it’s less commonly used and may not cover all commit-related endpoints.
   Why: The /repository/commits endpoint requires authentication to access private repos and detailed commit data. The api scope ensures broad compatibility.
   How to Generate:
   Go to GitLab > User Settings > Access Tokens.
   Create a personal access token, select the api scope, and set an expiration (optional).
   Copy the token and use it as the api_key in the plugin settings.
   Note: For self-hosted GitLab instances, the process is the same, but the instance_url must point to your instance.

3. Gitea

   API Endpoint: GET /api/v1/repos/{owner}/{repo}/commits
   Required Permissions:
   Gitea doesn’t use granular OAuth scopes like GitHub or GitLab. Instead, it relies on a single Access Token with broad permissions.
   The token must have repository read access (implicitly granted when created with admin or repo owner privileges).
   Why: Gitea’s API requires authentication for private repos, and the token acts as a full-access key to the user’s repositories.
   How to Generate:
   Log into your Gitea instance > User Settings > Applications.
   Generate a new access token (no specific scopes to select; it inherits your user permissions).
   Copy the token and use it as the api_key, ensuring the instance_url is set to your Gitea instance (e.g., https://gitea.example.com).
   Note: Permissions depend on the user creating the token. For private repos, the user must have read access to the repo.

4. Bitbucket

   API Endpoint: GET /2.0/repositories/{workspace}/{repo_slug}/commits
   Required Permissions:
   repository Scope: Grants read access to repository data, including commits.
   For private repositories, this scope is essential.
   Optional: If using an App Password instead of OAuth, select Repositories > Read permission.
   Why: The /commits endpoint requires authentication to access private repo commit history. The repository scope (or equivalent App Password permission) ensures this access.
   How to Generate:
   Option 1: OAuth Bearer Token:
   Go to Bitbucket > Workspace settings > OAuth consumers > Add consumer.
   Set permissions to repository (read access), complete the OAuth flow, and obtain a Bearer token.
   Use this token as the api_key.
   Option 2: App Password (Simpler):
   Go to Bitbucket > Personal settings > App passwords.
   Create a new App Password, check Repositories > Read.
   Copy the generated password and use it as the api_key.
   Note: Bitbucket uses {workspace}/{repo_slug} in the API, so ensure username is your workspace ID and repo matches the slug.

Summary of Permissions
Provider
API Key Type
Required Scope/Permission
Notes
GitHub
Personal Access Token
repo
Full repo access for private data
GitLab
Personal Access Token
api
Broad API access;
read_api
may work
Gitea
Access Token
Repository read (implicit)
No scopes; depends on user permissions
Bitbucket
OAuth Token or App Password
repository
or Repositories > Read
Workspace/repo slug format required
Additional Considerations

    Public Repos: If git_activity_show_public_only is enabled in the plugin settings, no API key is strictly required for public repos, but rate limits will apply (e.g., GitHub: 60 requests/hour unauthenticated vs. 5000/hour with a token).
    Private Repos: Without the correct scopes, private repo data won’t be accessible, and the plugin will return “No activity data available” for those repos.
    Rate Limits: Using API keys with proper authentication significantly increases rate limits, making the plugin more reliable.
    Testing: After generating tokens, test with a public repo first, then a private one, to ensure permissions are correct.

How to Verify Permissions

    GitHub: Check the token’s scopes in your GitHub settings under Personal Access Tokens.
    GitLab: View the token details in Access Tokens; ensure api is listed.
    Gitea: No scope list, but test the token with a private repo via the API (e.g., curl -H "Authorization: token YOUR_TOKEN" YOUR_INSTANCE/api/v1/repos/USER/REPO/commits).
    Bitbucket: For App Passwords, permissions are shown when created; for OAuth, verify in OAuth consumer settings.

Let me know if you need help generating these keys or troubleshooting access issues!
