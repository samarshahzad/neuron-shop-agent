# Kiez Living – AI Shop Assistant (Symfony + Neuron AI)

An AI customer service agent for a demo home decor shop. Customers chat in English or German, and the agent **decides by itself which database tool to call**: look up an order, list a customer's orders, or search products. It then answers with real data from the database.

![Screenshot](docs/screenshot.png)

**Tech stack:** PHP 8.2+, Symfony 7.4, [Neuron AI](https://docs.neuron-ai.dev/) 4.1, Doctrine DBAL, SQLite or MySQL, vanilla JavaScript, PHPUnit.

## Features

- **Agent with tools:** `find_order`, `list_customer_orders` and `search_products`, each backed by a SQL query
- **Any AI provider via `.env`:** Ollama (free, local), Anthropic Claude, OpenAI or Gemini, with no code change
- **Persistent conversations:** chat history is stored in the database with Neuron's `SQLMessageStore`, so it survives reloads and restarts
- **Chat frontend:** shows which tool the agent used for each answer, plus a live panel with the orders table; works on mobile and in dark mode
- **Tests without an API key:** PHPUnit tests use Neuron's `FakeAIProvider` and an in-memory SQLite database

## How it works

```
Browser (chat.js) ──POST /api/chat──▶ ChatController ──▶ ChatService ──▶ ShopAssistantAgent (Neuron)
                                                                             │        │
                                                          AI model (.env) ◀──┘        └──▶ Tools ──▶ ShopRepository ──▶ Database
                                                                                                 (find_order, ...)        (DBAL)
```

1. The user's message and the conversation so far (loaded from the `chat_messages` table) are sent to the model, together with the tool descriptions.
2. The model replies with a tool call, for example `find_order(order_number: "SO-1003")`.
3. Neuron runs the PHP tool, which queries the database and returns JSON.
4. The model writes the final answer using that data. Every step is saved to the chat history.

## Quick start with Docker (recommended, works on Windows)

You only need [Docker Desktop](https://www.docker.com/products/docker-desktop/). No PHP, Composer or Symfony CLI required.

```powershell
cd neuron-shop-agent
Copy-Item .env.local.example .env.local   # then set AI_PROVIDER / AI_MODEL / AI_API_KEY in it
docker compose up --build
```

On the first start, Docker installs the Composer dependencies, creates the MySQL tables and loads the demo data. When you see *"running: http://localhost:8000"*, open http://localhost:8000.

- Database browser (Adminer): http://localhost:8081 (server `mysql`, user `shop`, password `shop`)
- Using Ollama? Run it on your computer as usual. The container reaches it via `host.docker.internal`.
- Reset the demo data: `docker compose exec app php bin/console app:db:setup --reset`
- Run the tests: `docker compose exec app composer test`
- Stop: `Ctrl+C`, then `docker compose down` (add `-v` to also delete the database)

## Quick start without Docker

```bash
git clone https://github.com/samarshahzad/neuron-shop-agent.git
cd neuron-shop-agent
composer install

# 1. Configure: copy the example and add your settings
cp .env.local.example .env.local

# 2. Create the tables and demo data (SQLite by default, no setup needed)
php bin/console app:db:setup

# 3. Start the server
symfony serve        # or: php -S localhost:8000 -t public
```

Open http://localhost:8000.

### Choose the AI model (in `.env.local`)

| Provider | Settings | Cost |
|---|---|---|
| Ollama (default) | `AI_PROVIDER=ollama`, `AI_MODEL=qwen3`; install [Ollama](https://ollama.com) and run `ollama pull qwen3` | Free, runs locally |
| Anthropic | `AI_PROVIDER=anthropic`, `AI_MODEL=<current Claude model>`, `AI_API_KEY=sk-ant-...` | Pay per use |
| OpenAI | `AI_PROVIDER=openai`, `AI_MODEL=<current model>`, `AI_API_KEY=sk-...` | Pay per use |
| Gemini | `AI_PROVIDER=gemini`, `AI_MODEL=<current model>`, `AI_API_KEY=...` | Free tier available |

Check the provider's docs for current model names. For tool calling with Ollama, use a model that supports tools (for example `qwen3` or `llama3.1`).

> `.env` holds safe defaults and is committed. `.env.local` holds your secrets and is git-ignored, so your API key never ends up on GitHub.

### Use MySQL instead of SQLite (without running the app in Docker)

```bash
docker compose up -d mysql adminer   # only MySQL 8.4 + Adminer on http://localhost:8081
```

Then set this in `.env.local` and recreate the tables:

```dotenv
DATABASE_URL="mysql://shop:shop@127.0.0.1:3306/shop_agent?serverVersion=8.4&charset=utf8mb4"
```

```bash
php bin/console app:db:setup --reset
```

## Try these questions

- *Where is my order SO-1002?*
- *Wo ist meine Bestellung SO-1003?*
- *Show orders for lena.fischer@example.com*
- *Which lamps are in stock?*
- *Gifts under 30 €?*

Demo data: 4 customers, 10 products and 7 orders (SO-1001 to SO-1007) with different statuses.

## Tests

```bash
composer test
```

The tests run the real agent, tools and database with a fake AI provider, so they need no API key and no network.

## Project structure

```
src/
├── Command/SetupDatabaseCommand.php   # bin/console app:db:setup
├── Controller/ChatController.php      # page + JSON API (/api/chat, /api/history, /api/orders)
├── Database/ShopDatabase.php          # schema (portable DBAL) + demo data
├── Neuron/
│   ├── ShopAssistantAgent.php         # agent instructions
│   ├── ShopAgentFactory.php           # wires provider, tools and chat history
│   ├── AiProviderFactory.php          # picks the provider from .env
│   ├── ChatService.php                # send / history / clear
│   └── Tools/                         # FindOrderTool, CustomerOrdersTool, SearchProductsTool
└── Repository/ShopRepository.php      # read-only SQL queries
templates/chat/index.html.twig         # chat page
public/js/chat.js, public/css/chat.css # frontend
tests/Neuron/ShopAgentTest.php         # PHPUnit tests with FakeAIProvider
```

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/chat` | Body `{"threadId": "...", "message": "..."}` → `{"reply": "...", "tools_used": [...]}` |
| `GET` | `/api/history?threadId=...` | Visible conversation (user and assistant messages) |
| `DELETE` | `/api/history?threadId=...` | Clear a conversation |
| `GET` | `/api/orders` | Latest orders for the side panel |

## Security note

This is a demo. `list_customer_orders` returns orders for any email address someone types in. A real shop would identify the customer through login and only let the agent see that customer's orders. The tools are also read-only on purpose. For actions like cancelling an order, add a tool with Neuron's `approvalPolicy()` so a human confirms first.

## Ideas for next steps

- Add a `cancel_order` tool with human approval (Neuron tool approval)
- Stream answers token by token (`$agent->stream()`)
- Use the logged-in user instead of email lookup (Symfony Security)
- Add RAG over shop FAQ pages (shipping, returns) with Neuron's RAG module

## License

MIT
