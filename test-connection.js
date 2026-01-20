#!/usr/bin/env node

/**
 * Test script to verify poc-ingest.js server is working correctly
 * This simulates what the Nightwatch agent does when connecting to the server
 */

const net = require("net");
const http = require("http");
const zlib = require("zlib");

const HTTP_PORT = process.env.HTTP_PORT || 3000;
const TCP_PORT = process.env.TCP_PORT || 2407;
const NIGHTWATCH_TOKEN = process.env.NIGHTWATCH_TOKEN || "dev-token";
const BASE_URL = `http://localhost:${HTTP_PORT}`;

console.log("=== Nightwatch POC Connection Test ===\n");

// Test 1: HTTP Authentication
async function testAuthentication() {
    return new Promise((resolve, reject) => {
        console.log("Test 1: HTTP Authentication");
        console.log(`  - URL: ${BASE_URL}/api/agent-auth`);
        console.log(`  - Token: ${NIGHTWATCH_TOKEN}`);

        const data = JSON.stringify({});
        const options = {
            hostname: "localhost",
            port: HTTP_PORT,
            path: "/api/agent-auth",
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Content-Length": data.length,
                Authorization: `Bearer ${NIGHTWATCH_TOKEN}`,
            },
        };

        const req = http.request(options, (res) => {
            let body = "";
            res.on("data", (chunk) => (body += chunk));
            res.on("end", () => {
                if (res.statusCode === 200) {
                    const response = JSON.parse(body);
                    console.log("  ✅ Authentication successful");
                    console.log(`  - Access token: ${response.token}`);
                    console.log(`  - Ingest URL: ${response.ingest_url}`);
                    console.log(`  - Expires in: ${response.expires_in}s`);
                    resolve(response);
                } else {
                    console.log(`  ❌ Authentication failed: ${res.statusCode}`);
                    console.log(`  - Response: ${body}`);
                    reject(new Error(`Auth failed: ${res.statusCode}`));
                }
                console.log();
            });
        });

        req.on("error", (error) => {
            console.log(`  ❌ Connection error: ${error.message}`);
            console.log();
            reject(error);
        });

        req.write(data);
        req.end();
    });
}

// Test 2: HTTP Ingest
async function testHttpIngest(accessToken) {
    return new Promise((resolve, reject) => {
        console.log("Test 2: HTTP Ingest Endpoint");
        console.log(`  - URL: ${BASE_URL}/api/ingest`);

        const testPayload = [
            {
                type: "request_started",
                uuid: "test-uuid-123",
                timestamp: Math.floor(Date.now() / 1000),
                method: "GET",
                uri: "/test",
                hostname: "test-server",
            },
            {
                type: "query",
                uuid: "test-uuid-123",
                connection_name: "mysql",
                sql: "SELECT * FROM users WHERE id = ?",
                bindings: [1],
                duration: 12.34,
            },
            {
                type: "request_finished",
                uuid: "test-uuid-123",
                duration: 156.78,
                status: 200,
                memory: 2048000,
            },
        ];

        const jsonData = JSON.stringify(testPayload);

        // Gzip the payload
        zlib.gzip(jsonData, (err, compressed) => {
            if (err) {
                console.log(`  ❌ Gzip error: ${err.message}`);
                console.log();
                return reject(err);
            }

            const options = {
                hostname: "localhost",
                port: HTTP_PORT,
                path: "/api/ingest",
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Content-Encoding": "gzip",
                    "Content-Length": compressed.length,
                    Authorization: `Bearer ${accessToken}`,
                },
            };

            const req = http.request(options, (res) => {
                let body = "";
                res.on("data", (chunk) => (body += chunk));
                res.on("end", () => {
                    if (res.statusCode === 200) {
                        console.log("  ✅ HTTP ingest successful");
                        console.log(`  - Sent ${testPayload.length} test records`);
                        resolve();
                    } else {
                        console.log(`  ❌ HTTP ingest failed: ${res.statusCode}`);
                        console.log(`  - Response: ${body}`);
                        reject(new Error(`Ingest failed: ${res.statusCode}`));
                    }
                    console.log();
                });
            });

            req.on("error", (error) => {
                console.log(`  ❌ Connection error: ${error.message}`);
                console.log();
                reject(error);
            });

            req.write(compressed);
            req.end();
        });
    });
}

// Test 3: TCP Socket Connection
async function testTcpConnection() {
    return new Promise((resolve, reject) => {
        console.log("Test 3: TCP Socket Connection");
        console.log(`  - Address: 127.0.0.1:${TCP_PORT}`);

        const client = new net.Socket();
        let connected = false;
        let receivedAck = false;

        const timeout = setTimeout(() => {
            if (!connected) {
                console.log("  ❌ Connection timeout");
                console.log();
                client.destroy();
                reject(new Error("Connection timeout"));
            }
        }, 5000);

        client.connect(TCP_PORT, "127.0.0.1", () => {
            connected = true;
            clearTimeout(timeout);
            console.log("  ✅ TCP connection established");

            // Send a test message in Nightwatch format
            // Format: LENGTH:PAYLOAD_VERSION:TOKEN_HASH:DATA
            const testPayload = [
                {
                    type: "request_started",
                    uuid: "tcp-test-uuid-456",
                    timestamp: Math.floor(Date.now() / 1000),
                    method: "POST",
                    uri: "/api/test",
                    hostname: "tcp-test-server",
                },
            ];

            const jsonData = JSON.stringify(testPayload);
            const version = "v1";
            const tokenHash = "abc1234";
            
            // Build message: PAYLOAD_VERSION:TOKEN_HASH:DATA
            const message = `${version}:${tokenHash}:${jsonData}`;
            const messageLength = message.length;
            
            // Full payload: LENGTH:MESSAGE
            const fullMessage = `${messageLength}:${message}`;

            console.log(`  - Sending ${testPayload.length} test records via TCP`);
            client.write(fullMessage);
        });

        client.on("data", (data) => {
            const response = data.toString();
            if (response === "2:OK") {
                receivedAck = true;
                console.log("  ✅ Received acknowledgment from server");
                console.log("  ✅ TCP message sent successfully");
                console.log();
                client.end();
                resolve();
            } else {
                console.log(`  ⚠️ Unexpected response: ${response}`);
            }
        });

        client.on("error", (error) => {
            clearTimeout(timeout);
            console.log(`  ❌ TCP connection error: ${error.message}`);
            console.log();
            reject(error);
        });

        client.on("close", () => {
            if (connected && !receivedAck) {
                console.log("  ⚠️ Connection closed without acknowledgment");
            }
        });
    });
}

// Run all tests
async function runTests() {
    try {
        // Test 1: Authentication
        const authResponse = await testAuthentication();

        // Test 2: HTTP Ingest
        await testHttpIngest(authResponse.token);

        // Test 3: TCP Socket
        await testTcpConnection();

        console.log("=== All Tests Passed ✅ ===\n");
        console.log("Your poc-ingest.js server is working correctly!");
        console.log("You can now connect your Laravel application.\n");
        process.exit(0);
    } catch (error) {
        console.log("\n=== Tests Failed ❌ ===\n");
        console.log("Error:", error.message);
        console.log("\nMake sure poc-ingest.js is running:");
        console.log(`  NIGHTWATCH_TOKEN=${NIGHTWATCH_TOKEN} node poc-ingest.js\n`);
        process.exit(1);
    }
}

// Start tests
console.log("Starting connection tests...");
console.log(`Configuration:`);
console.log(`  - HTTP Port: ${HTTP_PORT}`);
console.log(`  - TCP Port: ${TCP_PORT}`);
console.log(`  - Token: ${NIGHTWATCH_TOKEN}`);
console.log();

runTests();