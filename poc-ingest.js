const express = require("express");
const net = require("net");
const zlib = require("zlib");

const app = express();

// Configuration
const HTTP_PORT = process.env.HTTP_PORT || 3000;
const TCP_PORT = process.env.TCP_PORT || 2407;
const EXPECTED_REFRESH_TOKEN = process.env.NIGHTWATCH_TOKEN || "dev-token";
const ACCESS_TOKEN = "ingest-access-token";

// Auth endpoint: validate refresh token and return ingest details
app.post("/api/agent-auth", express.json(), (req, res) => {
    const auth = req.headers["authorization"] || "";
    const refreshToken = auth.startsWith("Bearer ") ? auth.slice(7) : "";

    if (!refreshToken) {
        return res.status(401).json({ message: "Missing refresh token" });
    }
    if (refreshToken !== EXPECTED_REFRESH_TOKEN) {
        return res.status(403).json({ message: "Invalid refresh token" });
    }

    // Return access token + ingest_url for the agent to POST data to
    return res.json({
        token: ACCESS_TOKEN,
        expires_in: 3600,
        refresh_in: 300,
        ingest_url: `http://localhost:${HTTP_PORT}/api/ingest`,
    });
});

// Ingest endpoint: accept gzip payload, decompress it, and log the result
app.post("/api/ingest", (req, res) => {
    const auth = req.headers["authorization"] || "";
    const accessToken = auth.startsWith("Bearer ") ? auth.slice(7) : "";
    if (accessToken !== ACCESS_TOKEN) {
        return res.status(401).json({ message: "Bad ingest token" });
    }

    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => {
        const buf = Buffer.concat(chunks);
        const enc = (req.headers["content-encoding"] || "").toLowerCase();

        const handleBody = (err, body) => {
            if (err) {
                console.error("Gunzip error:", err);
                return res.status(400).json({ message: "Bad gzip" });
            }
            try {
                const text = body.toString("utf8");
                console.log("\n=== HTTP Ingest payload ===");
                console.log(text);
                console.log("=========================\n");
                // text is a JSON array of Nightwatch records
                const records = JSON.parse(text);
                console.log(`Received ${records.length} records via HTTP`);
                return res.status(200).json({ message: "ok" });
            } catch (e) {
                console.error("Parse error:", e);
                return res.status(400).json({ message: "Bad payload" });
            }
        };

        if (enc === "gzip") {
            zlib.gunzip(buf, handleBody);
        } else {
            handleBody(null, buf);
        }
    });
});

// Start HTTP server
app.listen(HTTP_PORT, () => {
    console.log(`HTTP server listening on http://localhost:${HTTP_PORT}`);
    console.log(`  - POST /api/agent-auth (authentication)`);
    console.log(`  - POST /api/ingest (receive data)`);
});

// TCP Socket Server - This is what Laravel Nightwatch connects to
const tcpServer = net.createServer((socket) => {
    console.log(`TCP client connected from ${socket.remoteAddress}:${socket.remotePort}`);

    let buffer = "";

    socket.on("data", (chunk) => {
        buffer += chunk.toString("utf8");

        // Try to parse complete messages
        // Nightwatch sends: LENGTH:PAYLOAD_VERSION:TOKEN_HASH:DATA
        while (buffer.length > 0) {
            // Find the first colon to get the length
            const colonIndex = buffer.indexOf(":");
            if (colonIndex === -1) {
                // Wait for more data
                break;
            }

            const lengthStr = buffer.substring(0, colonIndex);
            const messageLength = parseInt(lengthStr, 10);

            if (isNaN(messageLength)) {
                console.error("Invalid message length:", lengthStr);
                socket.end();
                return;
            }

            // Check if we have the complete message
            // Total length = lengthStr + ':' + messageLength
            const totalLength = lengthStr.length + 1 + messageLength;
            if (buffer.length < totalLength) {
                // Wait for more data
                break;
            }

            // Extract the complete message (skip length prefix and colon)
            const message = buffer.substring(colonIndex + 1, totalLength);
            buffer = buffer.substring(totalLength);

            // Process the message and send acknowledgment
            const success = processNightwatchMessage(message, socket);
            if (success) {
                // Send acknowledgment: "2:OK"
                socket.write("2:OK");
            }
        }
    });

    socket.on("end", () => {
        console.log("TCP client disconnected");
    });

    socket.on("error", (err) => {
        console.error("TCP socket error:", err.message);
    });

    socket.on("close", () => {
        // Connection closed
    });
});

function processNightwatchMessage(message, socket) {
    try {
        // The message format from Nightwatch is:
        // PAYLOAD_VERSION:TOKEN_HASH:DATA
        // Example: "v1:abc1234:{"type":"PING"}" or "v1:abc1234:[{...}]"

        const parts = message.split(":");
        if (parts.length < 3) {
            console.error("Invalid message format:", message.substring(0, 100));
            return false;
        }

        const version = parts[0]; // e.g., "v1"
        const tokenHash = parts[1]; // e.g., "abc1234"
        const payload = parts.slice(2).join(":"); // Rest is payload (may contain colons)

        console.log(`\n=== TCP Message ===`);
        console.log(`Version: ${version}`);
        console.log(`Token hash: ${tokenHash}`);

        // Handle PING messages
        if (payload === "PING") {
            console.log("Payload: PING (health check)");
            console.log("===================\n");
            return true;
        }

        // Parse JSON payload
        try {
            const records = JSON.parse(payload);
            console.log(`Payload: ${Array.isArray(records) ? records.length : 1} record(s)`);
            console.log(JSON.stringify(records, null, 2));
            console.log(`Received ${Array.isArray(records) ? records.length : 1} records via TCP`);
            console.log("===================\n");
            return true;
        } catch (e) {
            console.error("Failed to parse JSON payload:", e.message);
            console.log("Raw payload:", payload.substring(0, 200));
            console.log("===================\n");
            return false;
        }
    } catch (err) {
        console.error("Error processing Nightwatch message:", err.message);
        return false;
    }
}

// Start TCP server
tcpServer.listen(TCP_PORT, "127.0.0.1", () => {
    console.log(`TCP socket server listening on 127.0.0.1:${TCP_PORT}`);
    console.log(`  - Waiting for Laravel Nightwatch connections...\n`);
});

tcpServer.on("error", (err) => {
    console.error("TCP server error:", err.message);
    process.exit(1);
});

// Graceful shutdown
process.on("SIGTERM", () => {
    console.log("\nShutting down gracefully...");
    tcpServer.close(() => {
        console.log("TCP server closed");
        process.exit(0);
    });
});

process.on("SIGINT", () => {
    console.log("\nShutting down gracefully...");
    tcpServer.close(() => {
        console.log("TCP server closed");
        process.exit(0);
    });
});