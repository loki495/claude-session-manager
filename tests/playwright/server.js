const { spawn } = require('node:child_process');
const os = require('node:os');

const root = `${__dirname}/../..`;
const socket = `${os.tmpdir()}/sessioneer-playwright-agent-${process.pid}.sock`;
const children = [];

function start(command, args, env) {
  const child = spawn(command, args, {
    cwd: root,
    env: { ...process.env, ...env },
    stdio: 'ignore',
  });
  children.push(child);
  return child;
}

start('php', [
  'tests/lib/socket_harness.php',
  socket,
  'php',
  'tests/fixtures/canned_agent.php',
]);

start('php', [
  '-S',
  '127.0.0.1:18100',
  '-t',
  'public',
  'public/index.php',
], { SESSIONEER_AGENT_SOCKET: socket });

function shutdown() {
  for (const child of children) {
    child.kill('SIGTERM');
  }
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
process.on('exit', shutdown);
