process.on('uncaughtException', (err) => console.error('💥 Uncaught Exception:', err));
process.on('unhandledRejection', (reason, promise) => console.error('💥 Unhandled Rejection at:', promise, 'reason:', reason));

console.log('Starting bridge server…');


import express from 'express';
const app = express();
app.get('/', (req, res) => res.send('Hello'));
app.listen(8080, () => console.log('Server running'));
